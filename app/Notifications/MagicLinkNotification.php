<?php

namespace App\Notifications;

use App\Services\OrganizationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLinkNotification extends Notification
{
    use Queueable;

    /** The code goes only into the mail text, never into the subject (the mail log keeps subjects) */
    public function __construct(private readonly string $rawToken, private readonly ?string $code = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('auth.magic-link.confirm', ['token' => $this->rawToken]);
        $ttl = config('magic-link.ttl_minutes', 15);

        $org = app(OrganizationSettings::class);

        $mail = (new MailMessage)
            ->subject('Dein Login-Link für '.$org->shortName())
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Jemand hat einen Login-Link für dein Konto angefordert.')
            ->action('Jetzt anmelden', $url);
        if ($this->code !== null) {
            // For the app on the home screen (iPhone): a link from the mail opens Safari, not the app
            $mail->line('Du nutzt die App auf dem Home-Bildschirm? Dann gib dort diesen Code ein: **'.$this->code.'**');
        }

        return $mail
            ->line("Link und Code sind {$ttl} Minuten gültig und funktionieren nur einmal.")
            ->line('Falls du keinen Link angefordert hast, kannst du diese E-Mail ignorieren.')
            ->salutation($org->salutation());
    }
}
