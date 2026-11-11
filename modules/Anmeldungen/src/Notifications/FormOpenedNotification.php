<?php

namespace Modules\Anmeldungen\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Anmeldungen\src\Models\Form;

/** A Kamerad of the audience: an Anmeldung is open (or a reminder before the Anmeldeschluss) */
class FormOpenedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Form $form, private readonly bool $reminder = false) {}

    public function toMail(object $notifiable): MailMessage
    {
        $f = $this->form;

        return (new MailMessage)
            ->subject(($this->reminder ? 'Erinnerung: Anmeldung ' : 'Anmeldung offen: ').$f->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line(($this->reminder ? 'du bist noch nicht angemeldet für ' : 'die Anmeldung ist offen für ').'**'.$f->title.'**'
                .($f->dateLabel() ? ' ('.$f->dateLabel().')' : '').'.')
            ->line($f->deadline ? 'Anmeldeschluss: '.$f->deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i').' Uhr.' : 'Bitte melde dich bald an.')
            ->action('Zur Anmeldung', route('anmeldungen.show', $f))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
