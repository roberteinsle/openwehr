<?php

namespace Modules\Uebungsdienste\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Uebungsdienste\src\Models\Swap;

/** To the Kamerad asked: someone wants to swap Übungsdienste and waits for the answer */
class SwapRequestedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Swap $swap) {}

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->swap;

        return (new MailMessage)
            ->subject('Übungsdienst tauschen? '.$s->requester->name.' fragt an')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($s->requester->name.' möchte den Übungsdienst am '.$s->service->dateLabel().' mit deinem am '.$s->targetService->dateLabel().' tauschen.')
            ->line('Stimmst du zu, übernimmst du den Dienst am '.$s->service->dateLabel().' und '.$s->requester->name.' deinen. Bis du antwortest, sehen alle im Plan, dass der Tausch angefragt ist.')
            ->action('Tausch beantworten', route('uebungsdienste.index', ['jahr' => $s->service->date->year]))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
