<?php

namespace Modules\Mitteilungen\src\Services;

use App\Models\MemberFunction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Models\AnnouncementFile;
use Modules\Mitteilungen\src\Notifications\AnnouncementNotification;
use Throwable;

class AnnouncementService
{
    /**
     * @param  array{title: string, body?: ?string, important: bool, needs_ack?: bool, visible_from?: mixed, visible_until?: mixed}  $data
     * @param  int[]  $groupIds  audience by Abteilung; with $functionIds empty = everyone
     * @param  int[]  $functionIds  audience by Funktion
     * @param  list<UploadedFile>  $files  attachments to add
     */
    public function save(?Announcement $announcement, array $data, array $groupIds, User $by, bool $mail = false, bool $push = false, array $functionIds = [], array $files = []): Announcement
    {
        foreach ($files as $file) {
            if (! in_array(mb_strtolower($file->getClientOriginalExtension()), AnnouncementFile::EXTENSIONS, true) || $file->getSize() > AnnouncementFile::MAX_KB * 1024) {
                throw ValidationException::withMessages(['files' => '„'.$file->getClientOriginalName().'“: bitte PDF, Office-Datei oder Bild bis 10 MB.']);
            }
        }

        $announcement = DB::transaction(function () use ($announcement, $data, $groupIds, $functionIds, $by, $files) {
            $announcement ??= new Announcement(['created_by' => $by->id]);
            // The Wachen-Monitor hangs openly in the station: Mitteilungen for everyone or for Abteilungen
            // (shown under their name) go there, never ones for Funktionen
            if ($functionIds !== []) {
                $data['on_monitor'] = false;
            }
            $announcement->fill($data)->save();
            $announcement->groups()->sync($groupIds);
            $announcement->functions()->sync($functionIds);
            foreach ($files as $file) {
                $announcement->files()->create(['filename' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(), 'data' => base64_encode((string) file_get_contents($file->getRealPath()))]);
            }

            return $announcement;
        });

        if ($mail) {
            $this->mail($announcement);
        } elseif ($push) {
            $this->mail($announcement, ['database']);
        }

        return $announcement;
    }

    /** Everyone it is meant for and who may open the Mitteilungen, so links don't end in "kein Zugriff" */
    public function recipients(Announcement $announcement): EloquentCollection
    {
        $groupIds = $announcement->groups()->pluck('groups.id');
        $functionIds = MemberFunction::idsWithChildren($announcement->functions()->pluck('member_functions.id')->all());
        $everyone = $groupIds->isEmpty() && $functionIds === [];

        return User::active()
            ->when(! $everyone, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('groups', fn ($g) => $g->whereIn('groups.id', $groupIds))
                ->orWhereHas('functions', fn ($f) => $f->whereIn('member_functions.id', $functionIds))))
            ->orderBy('name')->get()->filter(fn (User $user) => $user->canUseModule('mitteilungen'))->values();
    }

    /**
     * Everyone it is meant for gets it by mail, under the bell and as push; with $only = ['database']
     * just the bell and push (a short notice, e.g. a changed time). A mail problem is logged, not thrown.
     *
     * @param  ?EloquentCollection  $to  only these (e.g. who hasn't confirmed yet)
     */
    public function mail(Announcement $announcement, ?array $only = null, ?EloquentCollection $to = null, bool $reminder = false): int
    {
        $sent = 0;
        ($to ?? $this->recipients($announcement))->each(function (User $user) use ($announcement, $only, $reminder, &$sent) {
            try {
                $notification = new AnnouncementNotification($announcement, $reminder);
                $user->notify($only ? $notification->onlyVia($only) : $notification);
                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $sent;
    }

    /** Opened (the Mitteilungen page or an attachment): counted once */
    public function markRead(Announcement $announcement, User $user): void
    {
        DB::table('announcement_reads')->insertOrIgnore(['announcement_id' => $announcement->id, 'user_id' => $user->id, 'read_at' => now()]);
    }

    /** "Gelesen und verstanden" for a Mitteilung with Lesebestätigung */
    public function acknowledge(Announcement $announcement, User $user): void
    {
        $this->markRead($announcement, $user);
        DB::table('announcement_reads')->where('announcement_id', $announcement->id)->where('user_id', $user->id)->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);
    }

    /** @return array{recipients: int, read: int, acknowledged: int, missing: EloquentCollection} missing = not confirmed (or not read, without Lesebestätigung) */
    public function stats(Announcement $announcement): array
    {
        $recipients = $this->recipients($announcement);
        $reads = DB::table('announcement_reads')->where('announcement_id', $announcement->id)->whereIn('user_id', $recipients->pluck('id'))->get()->keyBy('user_id');

        return [
            'recipients' => $recipients->count(), 'read' => $reads->count(), 'acknowledged' => $reads->whereNotNull('acknowledged_at')->count(),
            'missing' => $recipients->reject(fn (User $u) => $announcement->needs_ack ? isset($reads[$u->id]) && $reads[$u->id]->acknowledged_at : isset($reads[$u->id]))->values(),
        ];
    }

    /** Who hasn't confirmed yet is asked again (bell, push, mail) */
    public function remindUnacknowledged(Announcement $announcement): int
    {
        return $this->mail($announcement, null, $this->stats($announcement)['missing'], true);
    }

    public function dismiss(Announcement $announcement, User $user): void
    {
        // One with Lesebestätigung stays until confirmed
        if (! $announcement->important && (! $announcement->needs_ack || $this->acknowledged($announcement, $user))) {
            $announcement->dismissedBy()->syncWithoutDetaching([$user->id]);
        }
    }

    public function acknowledged(Announcement $announcement, User $user): bool
    {
        return DB::table('announcement_reads')->where('announcement_id', $announcement->id)->where('user_id', $user->id)->whereNotNull('acknowledged_at')->exists();
    }
}
