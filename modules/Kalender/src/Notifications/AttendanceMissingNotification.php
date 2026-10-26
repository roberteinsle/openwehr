<?php

namespace Modules\Kalender\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Kalender\src\Models\Event;

/** To the Wehrführer: 24 hours after a Dienst no attendance is recorded, so no list went out */
class AttendanceMissingNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Event $event) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Anwesenheit fehlt: '.$this->event->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Für **'.$this->event->title.'** ('.$this->event->timeLabel().') ist noch keine Anwesenheit erfasst, deshalb ist keine Liste verschickt worden.')
            ->action('Anwesenheit erfassen', route('kalender.show', $this->event))
            ->line('Nach dem Erfassen geht die Liste mit „Anwesenheit abschließen“ raus – oder automatisch zur nächsten vollen Stunde.')
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
