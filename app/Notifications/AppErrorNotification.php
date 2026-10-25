<?php

namespace App\Notifications;

use App\Models\AppError;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the admins: an error happened in the app (at most once a day per place) */
class AppErrorNotification extends Notification
{
    use InApp;

    public function __construct(private readonly AppError $error) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Fehler in openwehr: '.$this->error->shortClass())
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('In der App ist ein Fehler aufgetreten ('.$this->error->shortClass().', bisher '.$this->error->count.'×).')
            ->line(mb_substr($this->error->message, 0, 300))
            ->line('Ort: '.$this->error->location.($this->error->url ? ' · '.$this->error->url : ''))
            ->action('Analyse öffnen', url('/admin/analyse'))
            ->line('Zu derselben Stelle kommt höchstens einmal am Tag eine Nachricht.')
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
