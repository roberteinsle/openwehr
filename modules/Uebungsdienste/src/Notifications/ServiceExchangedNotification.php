<?php

namespace Modules\Uebungsdienste\src\Notifications;

use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Uebungsdienste\src\Models\Service;

/** To a responsible Kamerad: the Vorstand swapped two Übungsdienste (topic and responsible people) – this is the Dienst now */
class ServiceExchangedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Service $now, private readonly Service $before, private readonly User $by) {}

    public function toMail(object $notifiable): MailMessage
    {
        $when = fn (Service $s) => $s->dateLabel().($s->starts_at ? ', '.$s->starts_at.' Uhr' : '').($s->location ? ', '.$s->location : '');

        $mail = (new MailMessage)
            ->subject('Übungsdienst getauscht: jetzt am '.$this->now->dateLabel())
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->by->name.' hat zwei Übungsdienste getauscht: Du bist jetzt für den Dienst am '.$when($this->now).' verantwortlich statt für den am '.$this->before->dateLabel().'.')
            ->line($this->now->topic ? 'Thema: '.$this->now->topic : 'Ein Thema steht noch nicht fest.');
        if ($notifiable instanceof User && $notifiable->canUseModule('uebungsdienste')) {
            $mail->action('Übungsdienstplan ansehen', route('uebungsdienste.index', ['jahr' => $this->now->date->year]));
        }

        return $mail->salutation(app(OrganizationSettings::class)->salutation());
    }

    public function inApp(object $notifiable): array
    {
        return $notifiable instanceof User && $notifiable->canUseModule('uebungsdienste') ? [] : ['url' => route('home')];
    }
}
