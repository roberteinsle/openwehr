<?php

namespace Modules\Anmeldungen\src\Notifications;

use App\Models\User;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Anmeldungen\src\Models\Form;

/** An Erziehungsberechtigte(r): an Anmeldung for the child is open – fill in and sign it in the Eltern-Bereich */
class GuardianFormNotification extends Notification
{
    public function __construct(private readonly Form $form, private readonly User $child, private readonly bool $reminder = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $f = $this->form;

        return (new MailMessage)
            ->subject(($this->reminder ? 'Erinnerung: Anmeldung ' : 'Anmeldung: ').$f->title.' – '.$this->child->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('für **'.$this->child->name.'** ist die Anmeldung zu **'.$f->title.'**'.($f->dateLabel() ? ' ('.$f->dateLabel().')' : '').' offen'
                .($this->reminder ? ' – bisher fehlt sie noch.' : '.'))
            ->line('Angaben und Einverständniserklärung füllst du im Eltern-Bereich aus und unterschreibst direkt am Handy.'
                .($f->deadline ? ' Anmeldeschluss: '.$f->deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i').' Uhr.' : ''))
            ->action('Zur Anmeldung', route('parents.child', $this->child))
            ->line('Anmeldung im Eltern-Bereich mit dieser E-Mail-Adresse.')
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
