<?php

namespace Modules\Mitteilungen\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use App\Support\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Mitteilungen\src\Models\Announcement;

class AnnouncementNotification extends Notification
{
    use InApp;

    /** $reminder: a second call to confirm a Mitteilung with Lesebestätigung */
    public function __construct(private readonly Announcement $announcement, private readonly bool $reminder = false) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(($this->reminder ? 'Bitte bestätigen: ' : ($this->announcement->important ? 'Wichtig: ' : 'Mitteilung: ')).$this->announcement->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('**'.$this->announcement->title.'**');

        if (filled($this->announcement->body)) {
            // Rendered like in the app (line() would join all lines into one paragraph)
            $mail->line(Markdown::html($this->announcement->body));
        }

        if ($this->announcement->files()->exists()) {
            $mail->line('Anhänge: '.$this->announcement->files()->pluck('filename')->implode(', ').' – in der App.');
        }
        if ($this->announcement->needs_ack) {
            $mail->line('Bitte bestätige in der App, dass du die Mitteilung gelesen hast.');
        }

        return $mail
            ->action($this->announcement->needs_ack ? 'Lesen und bestätigen' : 'Zu den Mitteilungen', route('mitteilungen.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
