<?php

namespace Modules\Uebungsdienste\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Uebungsdienste\src\Models\Swap;

/** To the requester: the swap was accepted or declined */
class SwapAnsweredNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Swap $swap) {}

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->swap;
        $accepted = $s->status === 'accepted';

        return (new MailMessage)
            ->subject('Übungsdienst-Tausch '.($accepted ? 'angenommen' : 'abgelehnt').': '.$s->targetUser->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($accepted
                ? $s->targetUser->name.' hat zugestimmt: Du übernimmst jetzt den Übungsdienst am '.$s->targetService->dateLabel().', '.$s->targetUser->name.' deinen am '.$s->service->dateLabel().'.'
                : $s->targetUser->name.' möchte den Übungsdienst am '.$s->targetService->dateLabel().' nicht tauschen. Du bleibst für den am '.$s->service->dateLabel().' verantwortlich.')
            ->action('Übungsdienstplan ansehen', route('uebungsdienste.index', ['jahr' => $s->service->date->year]))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
