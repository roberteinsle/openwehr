<?php

namespace App\Notifications;

use App\Models\Group;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A Jugendlicher may now join the Übungsdienste of another Abteilung (e.g. from 16 at the Einsatzabteilung) */
class GuestGroupNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Group $group) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Du übst ab jetzt mit: '.$this->group->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('du darfst ab jetzt bei den Übungsdiensten der '.$this->group->name.' mitmachen. Die Termine stehen in deinem Kalender.')
            ->action('Zur Startseite', route('home'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
