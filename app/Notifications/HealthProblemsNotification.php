<?php

namespace App\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the admins, from the daily check: what needs attention (backup, certificate, scheduled jobs, disk) */
class HealthProblemsNotification extends Notification
{
    use InApp;

    /** @param  list<string>  $problems */
    public function __construct(private readonly array $problems) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('openwehr braucht Aufmerksamkeit: '.count($this->problems).' '.(count($this->problems) === 1 ? 'Punkt' : 'Punkte'))
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Die tägliche Prüfung hat Folgendes gefunden:');
        foreach ($this->problems as $problem) {
            $mail->line('– '.$problem);
        }

        return $mail->action('Analyse öffnen', url('/admin/analyse'))->salutation(app(OrganizationSettings::class)->salutation());
    }
}
