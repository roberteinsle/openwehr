<?php

namespace Modules\Kalender\src\Notifications;

use App\Models\User;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Kalender\src\Models\Event;

/** The Anwesenheit of a Dienst for the Wehrführung (and e.g. the Gemeinde): list in the mail, CSV attached; mail only */
class AttendanceReportNotification extends Notification
{
    /** @param  Collection<int, array{user: User, groups: string, status: string, comment: string, attended: string}>  $present */
    public function __construct(
        private readonly Event $event,
        private readonly Collection $present,
        private readonly int $listed,
        private readonly string $csv,
        private readonly bool $correction,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $event = $this->event;
        $date = $event->starts_at->copy()->setTimezone('Europe/Berlin');
        $mail = (new MailMessage)
            ->subject(($this->correction ? 'Korrektur – ' : '').'Anwesenheit: '.$event->title.' am '.$date->format('d.m.Y'))
            ->greeting('Hallo'.(isset($notifiable->name) ? ' '.$notifiable->name : '').',');
        if ($this->correction) {
            $mail->line('Die Anwesenheit wurde nach dem Abschluss geändert. Diese Liste ersetzt die vorherige.');
        }
        $mail->line('**'.$event->typeLabel().': '.$event->title.'**')->line($event->timeLabel());
        if ($audience = $event->audienceLabel()) {
            $mail->line('Für: '.$audience);
        }
        if ($place = $event->place?->label() ?? $event->location) {
            $mail->line('Ort: '.$place);
        }
        $mail->line('**Anwesend: '.$this->present->count().'** von '.$this->listed.' auf der Liste'.($event->attendanceClosedBy ? ' – abgeschlossen von '.$event->attendanceClosedBy->name : ' – automatisch 24 Stunden nach dem Ende verschickt').'.');
        foreach ($this->present as $row) {
            $mail->line('- '.$row['user']->name.($row['groups'] !== '' ? ' ('.$row['groups'].')' : ''));
        }

        return $mail->line('Die vollständige Liste mit Rückmeldungen steht als CSV im Anhang.')
            ->attachData($this->csv, 'anwesenheit-'.$date->format('Y-m-d').'-'.Str::slug($event->title).'.csv', ['mime' => 'text/csv'])
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
