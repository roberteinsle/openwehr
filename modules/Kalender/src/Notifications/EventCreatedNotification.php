<?php

namespace Modules\Kalender\src\Notifications;

use App\Models\DressCode;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Kalender\src\Models\Event;

class EventCreatedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Event $event, private readonly int $count = 1) {}

    public function toMail(object $notifiable): MailMessage
    {
        $event = $this->event;
        $mail = (new MailMessage)
            ->subject('Neuer Termin: '.$event->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('**'.$event->typeLabel().': '.$event->title.'**')
            ->line($event->timeLabel().($this->count > 1 ? ' – und '.($this->count - 1).' weitere Termine dieser Reihe' : ''));
        if ($audience = $event->audienceLabel()) {
            $mail->line('Für: '.$audience);
        }

        if ($event->place) {
            $mail->line('Ort: '.$event->place->label().' – Navigation: '.$event->place->navigationUrl());
        } elseif ($event->location) {
            $mail->line('Ort: '.$event->location);
        }
        if ($event->partners) {
            $mail->line('Gemeinsam mit '.$event->partners);
        }
        if ($dress = DressCode::names($event->dress_code_ids)) {
            $mail->line('Kleidung: '.implode(', ', $dress));
        }
        if ($event->description) {
            $mail->line($event->description);
        }
        if ($event->rsvp) {
            $mail->line('Bitte gib eine Rückmeldung'.($event->rsvp_deadline ? ' bis '.$event->rsvp_deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i').' Uhr' : '').'.');
        }

        return $mail->action($event->rsvp ? 'Zum Termin und Rückmeldung' : 'Zum Termin', route('kalender.show', $event))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
