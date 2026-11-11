<?php

namespace Modules\Kalender\src\Notifications;

use App\Models\User;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Modules\Kalender\src\Models\Event;

/**
 * The Kalender mail of a Kamerad (e.g. Jugendfeuerwehr) as a copy to an Erziehungsberechtigte(r).
 * The button leads to a signed page where they answer for the child without signing in; the Eltern-Bereich
 * (Eltern-Login with the same address) has all Termine.
 */
class GuardianCopyNotification extends Notification
{
    public function __construct(private readonly Notification $original, private readonly User $child, private readonly Event $event) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var MailMessage $original */
        $original = $this->original->toMail($this->child);
        $mail = (new MailMessage)
            ->subject($original->subject.' – '.$this->child->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('diese Nachricht geht an '.$this->child->name.' und an dich als Erziehungsberechtigte(n).');
        foreach ([...$original->introLines, ...$original->outroLines] as $line) {
            $mail->line($line);
        }

        if ($this->event->rsvp && $this->event->acceptsResponses()) {
            $mail->action('Rückmeldung für '.$this->child->name, self::url($this->event, $notifiable));
        }

        $mail->line('Alle Termine, das Kalender-Abo für dein Handy und mehr findest du im Eltern-Bereich: '.route('parents.index').' – Anmeldung mit dieser E-Mail-Adresse.');

        return $mail->salutation(app(OrganizationSettings::class)->salutation());
    }

    /** Valid until the event begins */
    public static function url(Event $event, object $guardian): string
    {
        return URL::temporarySignedRoute('kalender.guardian', $event->starts_at->copy()->max(now()->addHour()), ['event' => $event->id, 'guardian' => $guardian->id]);
    }
}
