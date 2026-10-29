<?php

namespace Modules\Kleiderkammer\src\Services;

use App\Enums\MemberGender;
use App\Enums\MemberStatus;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemCategory;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemTreatment;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\MemberSize;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;

/**
 * Exports and imports Kleiderkammer data as JSON-serializable arrays.
 * The file keeps three groups ('catalog', 'members', 'inventory') and stays compatible with the
 * old stand-alone Kleiderkammer app; IDs in a file are only used to resolve references within
 * that same file, never against the target database. What goes in or out is chosen by part.
 */
class DataTransferService
{
    /** Selectable parts of an export or import, in dependency order */
    public const PARTS = [
        'categories' => 'Kategorien',
        'item_types' => 'Artikel (mit Gruppen und Größen)',
        'items' => 'Einzelteile',
        'stock' => 'Massenbestand',
        'treatments' => 'Behandlungen (Wäsche, Prüfungen)',
        'issuances' => 'Ausgaben an Mitglieder (enthält die Mitgliedsdaten)',
        'members' => 'Mitglieder mit Konfektionsgrößen',
    ];

    /** Names of the three file groups, usable as shorthand for several parts */
    private const GROUP_PARTS = [
        'catalog' => ['categories', 'item_types'],
        'members' => ['members'],
        'inventory' => ['items', 'stock', 'treatments', 'issuances'],
    ];

    /**
     * @param  string[]|null  $parts  keys of self::PARTS or group names; null = everything
     * @return string[]
     */
    private function resolveParts(?array $parts): array
    {
        if ($parts === null) {
            return array_keys(self::PARTS);
        }

        $resolved = [];
        foreach ($parts as $part) {
            array_push($resolved, ...(self::GROUP_PARTS[$part] ?? [$part]));
        }

        // Issuances point at members through the file, so they need the member rows
        if (in_array('issuances', $resolved)) {
            $resolved[] = 'members';
        }

        return array_values(array_unique(array_intersect(array_keys(self::PARTS), $resolved)));
    }

    /** @param string[]|null $parts */
    public function export(?array $parts = null): array
    {
        $parts = $this->resolveParts($parts);
        $has = fn (string ...$wanted) => array_intersect($wanted, $parts) !== [];
        $data = ['version' => 1, 'exported_at' => now()->toIso8601String(), 'groups' => []];

        if ($has('categories', 'item_types')) {
            $data['groups']['catalog'] = $this->exportCatalog($has('categories'), $has('item_types'));
        }
        if ($has('members')) {
            $data['groups']['members'] = $this->exportMembers();
        }
        if ($has('items', 'stock', 'treatments', 'issuances')) {
            $data['groups']['inventory'] = $this->exportInventory($parts);
        }

        return $data;
    }

    /**
     * What a file contains: row counts per part and the item type names it mentions.
     *
     * @return array{parts: array<string, int>, types: string[]}
     */
    public function inspect(array $data): array
    {
        $groups = $data['groups'] ?? [];
        $names = $this->typeNames($data);

        $counts = [
            'categories' => count($groups['catalog']['categories'] ?? []),
            'item_types' => count($groups['catalog']['item_types'] ?? []),
            'items' => count($groups['inventory']['items'] ?? []),
            'stock' => count($groups['inventory']['stock'] ?? []),
            'treatments' => count($groups['inventory']['treatments'] ?? []),
            'issuances' => count($groups['inventory']['issued_items'] ?? []),
            'members' => count($groups['members']['members'] ?? []),
        ];

        $types = collect($groups['catalog']['item_types'] ?? [])->pluck('name')
            ->merge(collect($groups['inventory']['items'] ?? [])->map(fn ($r) => $this->typeNameOf($r, $names)))
            ->merge(collect($groups['inventory']['stock'] ?? [])->map(fn ($r) => $this->typeNameOf($r, $names)))
            ->filter()->unique()->sort()->values()->all();

        return ['parts' => array_filter($counts), 'types' => $types];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string[]|null  $parts  only these parts are imported; null = everything in the file
     * @param  string[]|null  $types  only rows of these item type names; null or empty = all
     * @return array{skipped_members: string[], issued_items: int, skipped_issued_items: int}
     */
    public function import(array $data, ?array $parts = null, ?array $types = null): array
    {
        $groups = $this->filter($data, $parts, $types)['groups'] ?? [];
        $report = ['skipped_members' => [], 'issued_items' => 0, 'skipped_issued_items' => 0];

        DB::transaction(function () use ($groups, &$report) {
            $catalog = $this->importCatalog($groups['catalog'] ?? []);
            $members = $this->importMembers($groups['members']['members'] ?? [], $report);
            $this->importMemberSizes($groups['members']['sizes'] ?? [], $catalog, $members);

            if (isset($groups['inventory'])) {
                $this->importInventory($groups['inventory'], $catalog, $members, $report);
            }
        });

        return $report;
    }

    /** Drops everything from the file that was not selected */
    private function filter(array $data, ?array $parts, ?array $types): array
    {
        $parts = $this->resolveParts($parts);
        $types = array_values(array_filter($types ?? []));
        $g = $data['groups'] ?? [];
        $names = $this->typeNames($data);

        $inTypes = fn (array $row) => $types === [] || in_array($this->typeNameOf($row, $names), $types, true);

        // item code / file id → type name, for rows that only point at an item
        $itemTypeOf = [];
        foreach ($g['inventory']['items'] ?? [] as $row) {
            $itemTypeOf['code:'.($row['code'] ?? '')] = $this->typeNameOf($row, $names);
            if (isset($row['id'])) {
                $itemTypeOf['id:'.$row['id']] = $this->typeNameOf($row, $names);
            }
        }
        $itemInTypes = function (array $row) use ($types, $itemTypeOf) {
            $name = $itemTypeOf['id:'.($row['item_id'] ?? '')]
                ?? $itemTypeOf['code:'.($row['item_code'] ?? '')]
                ?? Item::withTrashed()->where('code', $row['item_code'] ?? null)->first()?->itemType?->name;

            return $types === [] || in_array($name, $types, true);
        };

        $out = ['version' => $data['version'] ?? 1, 'groups' => []];

        if (isset($g['catalog'])) {
            $catalog = [];
            if (in_array('categories', $parts)) {
                $catalog['categories'] = $g['catalog']['categories'] ?? [];
            }
            if (in_array('item_types', $parts)) {
                $keep = fn (array $rows) => array_values(array_filter($rows, fn ($r) => $types === [] || in_array($names[$r['item_type_id'] ?? null] ?? null, $types, true)));
                $catalog['item_types'] = array_values(array_filter($g['catalog']['item_types'] ?? [], fn ($r) => $types === [] || in_array($r['name'] ?? null, $types, true)));
                $catalog['sizes'] = $keep($g['catalog']['sizes'] ?? []);
                $catalog['groups'] = $keep($g['catalog']['groups'] ?? []);
            }
            $out['groups']['catalog'] = $catalog;
        }

        if (isset($g['members']) && in_array('members', $parts)) {
            $out['groups']['members'] = [
                'members' => $g['members']['members'] ?? [],
                'sizes' => array_values(array_filter($g['members']['sizes'] ?? [], $inTypes)),
            ];
        }

        if (isset($g['inventory'])) {
            $inv = [];
            if (in_array('items', $parts)) {
                $inv['items'] = array_values(array_filter($g['inventory']['items'] ?? [], $inTypes));
            }
            if (in_array('stock', $parts)) {
                $inv['stock'] = array_values(array_filter($g['inventory']['stock'] ?? [], $inTypes));
            }
            if (in_array('treatments', $parts)) {
                $inv['treatments'] = array_values(array_filter($g['inventory']['treatments'] ?? [], $itemInTypes));
            }
            if (in_array('issuances', $parts)) {
                $inv['issued_items'] = array_values(array_filter(
                    $g['inventory']['issued_items'] ?? [],
                    fn ($r) => isset($r['item_id']) || isset($r['item_code']) ? $itemInTypes($r) : $inTypes($r),
                ));
            }
            $out['groups']['inventory'] = $inv;
        }

        return $out;
    }

    /** @return array<int|string, string> file type ID → type name */
    private function typeNames(array $data): array
    {
        return collect($data['groups']['catalog']['item_types'] ?? [])->mapWithKeys(fn ($r) => [$r['id'] ?? null => $r['name'] ?? null])->all();
    }

    private function typeNameOf(array $row, array $names): ?string
    {
        return ($row['item_type_name'] ?? null) ?: ($names[$row['item_type_id'] ?? null] ?? null);
    }

    // ─── Export ───────────────────────────────────────────────────────────────

    private function exportCatalog(bool $categories, bool $itemTypes): array
    {
        $catalog = [];

        if ($categories) {
            $catalog['categories'] = ItemCategory::orderBy('sort_order')->get(['id', 'name', 'sort_order'])->toArray();
        }
        if ($itemTypes) {
            $catalog['item_types'] = ItemType::with('category')->orderBy('sort_order')->get()
                ->map(fn (ItemType $t) => array_merge(Arr::except($t->toArray(), ['category']), ['category_name' => $t->category?->name]))
                ->all();
            $catalog['sizes'] = Size::orderBy('sort_order')->get()->toArray();
            $catalog['groups'] = ItemGroup::orderBy('sort_order')->get()->toArray();
        }

        return $catalog;
    }

    private function exportMembers(): array
    {
        return [
            'members' => User::with('groups')->orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'departments' => $user->groups->pluck('name')->all(),
                'role' => $user->rank,
                'gender' => $user->gender?->value,
                'status' => $user->status->value,
                'notes' => $user->notes,
            ])->all(),
            'sizes' => MemberSize::with(['itemType', 'group', 'size'])->get()->map(fn (MemberSize $m) => [
                'member_id' => $m->user_id,
                'item_type_name' => $m->itemType?->name,
                'group_name' => $m->group?->name,
                'size_name' => $m->size?->name,
            ])->all(),
        ];
    }

    private function exportInventory(array $parts): array
    {
        $inventory = [
            'items' => Item::withTrashed()->with(['itemType', 'size', 'group'])->orderBy('code')->get()->map(fn (Item $i) => array_merge(
                Arr::except($i->toArray(), ['created_by', 'updated_by', 'item_type', 'size', 'group']),
                ['item_type_name' => $i->itemType?->name, 'size_name' => $i->size?->name, 'group_name' => $i->group?->name],
            ))->all(),
            'stock' => Stock::with(['itemType', 'size', 'group'])->get()->map(fn (Stock $s) => array_merge(
                Arr::except($s->toArray(), ['item_type', 'size', 'group']),
                ['item_type_name' => $s->itemType?->name, 'size_name' => $s->size?->name, 'group_name' => $s->group?->name],
            ))->all(),
            'issued_items' => IssuedItem::withTrashed()->with(['item', 'itemType', 'size', 'group'])->orderBy('issued_at')->get()->map(fn (IssuedItem $i) => array_merge(
                Arr::except($i->toArray(), ['created_by', 'updated_by', 'user_id', 'item', 'item_type', 'size', 'group']),
                [
                    'member_id' => $i->user_id,
                    'item_code' => $i->item?->code,
                    'item_type_name' => $i->itemType?->name,
                    'size_name' => $i->size?->name,
                    'group_name' => $i->group?->name,
                ],
            ))->all(),
            'treatments' => ItemTreatment::with('item')->orderBy('treated_at')->get()->map(fn (ItemTreatment $t) => array_merge(
                Arr::except($t->toArray(), ['item']),
                ['item_code' => $t->item?->code],
            ))->all(),
        ];

        $keys = ['items' => 'items', 'stock' => 'stock', 'issuances' => 'issued_items', 'treatments' => 'treatments'];

        return array_intersect_key($inventory, array_flip(array_intersect_key($keys, array_flip($parts))));
    }

    // ─── Import ───────────────────────────────────────────────────────────────

    /** @return array{types: array<int, int>, sizes: array<int, int>, groups: array<int, int>} old ID → new ID */
    private function importCatalog(array $data): array
    {
        $categoryMap = [];
        foreach ($data['categories'] ?? [] as $row) {
            $category = ItemCategory::updateOrCreate(['name' => $row['name']], ['sort_order' => $row['sort_order'] ?? 0]);
            $categoryMap[$row['id']] = $category->id;
        }

        $typeMap = [];
        foreach ($data['item_types'] ?? [] as $row) {
            $originalId = $row['id'] ?? null;
            // The category comes with the file, or is found by name in the target database
            $row['category_id'] = (isset($row['category_id']) ? ($categoryMap[$row['category_id']] ?? null) : null)
                ?? (filled($row['category_name'] ?? null) ? ItemCategory::where('name', $row['category_name'])->value('id') : null);
            // Lager are local to an installation; an imported Artikel goes into the first one
            unset($row['id'], $row['created_at'], $row['updated_at'], $row['category_name'], $row['store_id']);
            $type = ItemType::updateOrCreate(['name' => $row['name']], $row);
            if ($originalId !== null) {
                $typeMap[$originalId] = $type->id;
            }
        }

        $groupMap = [];
        foreach ($data['groups'] ?? [] as $row) {
            $typeId = $typeMap[$row['item_type_id']] ?? null;
            if ($typeId === null) {
                continue;
            }
            $group = ItemGroup::updateOrCreate(
                ['item_type_id' => $typeId, 'name' => $row['name']],
                ['sort_order' => $row['sort_order'] ?? 0],
            );
            if (isset($row['id'])) {
                $groupMap[$row['id']] = $group->id;
            }
        }

        $sizeMap = [];
        foreach ($data['sizes'] ?? [] as $row) {
            $typeId = $typeMap[$row['item_type_id']] ?? null;
            if ($typeId === null) {
                continue;
            }
            $groupId = isset($row['group_id']) ? ($groupMap[$row['group_id']] ?? null) : null;
            $size = Size::updateOrCreate(
                ['item_type_id' => $typeId, 'group_id' => $groupId, 'name' => $row['name']],
                ['sort_order' => $row['sort_order'] ?? 0],
            );
            if (isset($row['id'])) {
                $sizeMap[$row['id']] = $size->id;
            }
        }

        return ['types' => $typeMap, 'sizes' => $sizeMap, 'groups' => $groupMap];
    }

    /** @return array<int, int> old member ID → user ID */
    private function importMembers(array $rows, array &$report): array
    {
        $map = [];

        foreach ($rows as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '') {
                $report['skipped_members'][] = $row['name'] ?? '?';

                continue;
            }

            $fields = [
                'rank' => $row['role'] ?? null,
                'gender' => MemberGender::tryFrom((string) ($row['gender'] ?? '')),
                'notes' => $row['notes'] ?? null,
            ];

            $user = User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

            if ($user === null) {
                $status = ! empty($row['deleted_at'])
                    ? MemberStatus::Left
                    : (MemberStatus::tryFrom((string) ($row['status'] ?? '')) ?? MemberStatus::Active);
                $user = User::create($fields + ['name' => $row['name'], 'email' => $email, 'status' => $status]);
            } else {
                // Existing accounts keep their name and login status.
                $user->update($fields);
            }

            // Abteilungen are added, never taken away; a missing one is created
            $groupIds = collect($this->departmentNames($row))
                ->map(fn (string $name) => Group::firstOrCreate(
                    ['name' => $name],
                    ['sort_order' => (int) Group::max('sort_order') + 1],
                )->id)
                ->all();
            $user->groups()->syncWithoutDetaching($groupIds);

            if (isset($row['id'])) {
                $map[$row['id']] = $user->id;
            }
        }

        return $map;
    }

    /** Abteilung names of a member row; files of the old app carry one fixed department value instead */
    private function departmentNames(array $row): array
    {
        $legacy = [
            'active_service' => 'Aktiver Dienst',
            'youth_brigade' => 'Jugendfeuerwehr',
            'music_group' => 'Musikzug',
            'honorary' => 'Ehrenabteilung',
            'leadership' => 'Wehrführung',
        ];

        $names = $row['departments'] ?? (isset($legacy[$row['department'] ?? null]) ? [$legacy[$row['department']]] : []);

        return array_values(array_filter(array_map('trim', $names), 'strlen'));
    }

    /** Clothing sizes from the member profiles; matched by type, group and size name */
    private function importMemberSizes(array $rows, array $catalog, array $members): void
    {
        foreach ($rows as $row) {
            $userId = $members[$row['member_id'] ?? null] ?? null;
            $type = $this->resolveType($row['item_type_name'] ?? null, null, $catalog);
            if ($userId === null || $type === null) {
                continue;
            }

            $group = $this->resolveGroup($type, $row['group_name'] ?? null, null, $catalog);
            $size = $this->resolveSize($type, $row['size_name'] ?? null, null, $catalog, $group);

            MemberSize::updateOrCreate(
                ['user_id' => $userId, 'item_type_id' => $type->id],
                ['group_id' => $group?->id, 'size_id' => $size?->id],
            );
        }
    }

    private function importInventory(array $data, array $catalog, array $members, array &$report): void
    {
        $itemMap = [];
        foreach ($data['items'] ?? [] as $row) {
            $type = $this->resolveType($row['item_type_name'] ?? null, $row['item_type_id'] ?? null, $catalog);
            if ($type === null) {
                continue;
            }

            $deletedAt = $row['deleted_at'] ?? null;
            $group = $this->resolveGroup($type, $row['group_name'] ?? null, $row['group_id'] ?? null, $catalog);
            $item = Item::withTrashed()->updateOrCreate(['code' => $row['code']], [
                'item_type_id' => $type->id,
                'size_id' => $this->resolveSize($type, $row['size_name'] ?? null, $row['size_id'] ?? null, $catalog, $group)?->id,
                'group_id' => $group?->id,
                'status' => $row['status'] ?? 'available',
                'notes' => $row['notes'] ?? null,
            ]);
            $this->syncTrashed($item, $deletedAt);

            if (isset($row['id'])) {
                $itemMap[$row['id']] = $item->id;
            }
        }

        foreach ($data['stock'] ?? [] as $row) {
            $type = $this->resolveType($row['item_type_name'] ?? null, $row['item_type_id'] ?? null, $catalog);
            if ($type === null) {
                continue;
            }
            $group = $this->resolveGroup($type, $row['group_name'] ?? null, $row['group_id'] ?? null, $catalog);
            $size = $this->resolveSize($type, $row['size_name'] ?? null, $row['size_id'] ?? null, $catalog, $group);

            Stock::updateOrCreate(
                ['item_type_id' => $type->id, 'size_id' => $size?->id, 'group_id' => $group?->id],
                ['quantity' => $row['quantity'] ?? 0],
            );
        }

        foreach ($data['issued_items'] ?? [] as $row) {
            $userId = $members[$row['member_id'] ?? null] ?? null;
            $itemId = isset($row['item_id'])
                ? ($itemMap[$row['item_id']] ?? Item::withTrashed()->where('code', $row['item_code'] ?? null)->value('id'))
                : null;
            $type = $itemId === null
                ? $this->resolveType($row['item_type_name'] ?? null, $row['item_type_id'] ?? null, $catalog)
                : null;

            if ($userId === null || ($itemId === null && $type === null) || empty($row['issued_at'])) {
                $report['skipped_issued_items']++;

                continue;
            }

            $issued = IssuedItem::withTrashed()->firstOrNew([
                'user_id' => $userId,
                'item_id' => $itemId,
                'item_type_id' => $type?->id,
                'issued_at' => $row['issued_at'],
            ]);
            $group = $type ? $this->resolveGroup($type, $row['group_name'] ?? null, $row['group_id'] ?? null, $catalog) : null;
            $issued->fill([
                'size_id' => $type ? $this->resolveSize($type, $row['size_name'] ?? null, $row['size_id'] ?? null, $catalog, $group)?->id : null,
                'group_id' => $group?->id,
                'quantity' => $row['quantity'] ?? 1,
                'returned_at' => $row['returned_at'] ?? null,
                'notes' => $row['notes'] ?? null,
            ])->save();
            $this->syncTrashed($issued, $row['deleted_at'] ?? null);
            $report['issued_items']++;
        }

        foreach ($data['treatments'] ?? [] as $row) {
            $itemId = $itemMap[$row['item_id'] ?? null] ?? Item::withTrashed()->where('code', $row['item_code'] ?? null)->value('id');
            if ($itemId === null || empty($row['treated_at']) || empty($row['type'])) {
                continue;
            }

            ItemTreatment::firstOrCreate(
                ['item_id' => $itemId, 'type' => $row['type'], 'treated_at' => $row['treated_at']],
                ['notes' => $row['notes'] ?? null],
            );
        }
    }

    private function resolveType(?string $name, mixed $originalId, array $catalog): ?ItemType
    {
        if ($name !== null && $name !== '') {
            return ItemType::where('name', $name)->first();
        }

        $id = $catalog['types'][$originalId] ?? null;

        return $id !== null ? ItemType::find($id) : null;
    }

    private function resolveSize(ItemType $type, ?string $name, mixed $originalId, array $catalog, ?ItemGroup $group = null): ?Size
    {
        if ($name !== null && $name !== '') {
            return Size::where('item_type_id', $type->id)->where('name', $name)
                ->forGroup($group?->id)
                ->orderByRaw('group_id is null')
                ->first();
        }

        $id = $catalog['sizes'][$originalId] ?? null;

        return $id !== null ? Size::where('item_type_id', $type->id)->find($id) : null;
    }

    private function resolveGroup(ItemType $type, ?string $name, mixed $originalId, array $catalog): ?ItemGroup
    {
        if ($name !== null && $name !== '') {
            return ItemGroup::where('item_type_id', $type->id)->where('name', $name)->first();
        }

        $id = $catalog['groups'][$originalId] ?? null;

        return $id !== null ? ItemGroup::where('item_type_id', $type->id)->find($id) : null;
    }

    private function syncTrashed(Item|IssuedItem $model, ?string $deletedAt): void
    {
        if ($deletedAt && ! $model->trashed()) {
            $model->delete();
        } elseif (! $deletedAt && $model->trashed()) {
            $model->restore();
        }
    }
}
