<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * An Ansprechpartner of the Wehr (Wehrführer, Jugendwart …) with phone and mail. visible_group_ids limits
 * who sees it to members of these Abteilungen (empty = everyone); parents see what their child sees.
 */
class Contact extends Model
{
    protected $fillable = ['role', 'name', 'phone', 'email', 'note', 'group_id', 'contact_group_id', 'sort_order', 'visible_group_ids'];

    protected function casts(): array
    {
        return ['visible_group_ids' => 'array'];
    }

    /** Whether members of these Abteilungen see it */
    public function visibleFor(array $groupIds): bool
    {
        $only = array_map('intval', (array) $this->visible_group_ids);

        return $only === [] || array_intersect($only, array_map('intval', $groupIds)) !== [];
    }

    /**
     * The Abteilungen whose Ansprechpartner this user sees; null = all of them (admins)
     *
     * @return ?list<int>
     */
    public static function groupIdsFor(User $user): ?array
    {
        return $user->is_admin ? null : $user->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->all();
    }

    /** Whether this user sees at least one Ansprechpartner (3×3 menu, Willkommen) */
    public static function anyFor(User $user): bool
    {
        return self::sections(self::groupIdsFor($user))->isNotEmpty();
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<ContactGroup, $this> */
    public function contactGroup(): BelongsTo
    {
        return $this->belongsTo(ContactGroup::class);
    }

    /**
     * The Ansprechpartner page in sections: first the Wehr's own (by Abteilung, title '' = without heading),
     * then the free groups in their order.
     *
     * @param  ?array<int>  $groupIds  only those visible for these Abteilungen; null = all
     * @return Collection<int, array{title: string, contacts: Collection<int, self>}>
     */
    public static function sections(?array $groupIds = null): Collection
    {
        $contacts = self::with('group', 'contactGroup')->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (self $c) => $groupIds === null || $c->visibleFor($groupIds))->values();
        [$grouped, $own] = $contacts->partition(fn (self $c) => $c->contactGroup !== null);
        $sections = collect();
        foreach ($own->groupBy(fn (self $c) => (string) $c->group_id) as $rows) {
            $sections->push(['title' => (string) $rows->first()?->group?->name, 'contacts' => $rows->values()]);
        }
        foreach ($grouped->sortBy(fn (self $c) => [$c->contactGroup?->sort_order, $c->contact_group_id])->groupBy('contact_group_id') as $rows) {
            $sections->push(['title' => (string) $rows->first()?->contactGroup?->name, 'contacts' => $rows->values()]);
        }

        return $sections;
    }

    /** Whom the printed Dienstplan lists: all but those in a group switched off for it */
    public static function forRoster(): Collection
    {
        return self::whereDoesntHave('contactGroup', fn ($q) => $q->where('on_roster', false))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** "tel:+491701234567" from what was typed */
    public function telUrl(): ?string
    {
        $digits = preg_replace('/[^0-9+]/', '', (string) $this->phone);

        return $digits !== '' ? 'tel:'.$digits : null;
    }
}
