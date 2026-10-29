<?php

namespace App\Livewire\Auth;

use App\Services\MagicLinkService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class LoginForm extends Component
{
    public string $email = '';

    public bool $sent = false;

    /** The code from the mail, for the app on the home screen (a link from the mail opens the browser instead) */
    public string $code = '';

    public function submit(MagicLinkService $service): void
    {
        // Livewire skips the TrimStrings middleware
        $this->email = trim($this->email);
        $this->validate([
            'email' => ['required', 'string', 'max:254', 'email'],
        ], [
            'email.required' => 'Bitte gib deine E-Mail-Adresse ein.',
            'email.*' => 'Bitte gib eine gültige E-Mail-Adresse ein.',
        ]);

        try {
            $service->sendLink($this->email, request());
        } catch (ValidationException $e) {
            $this->addError('email', $e->errors()['email'][0] ?? 'Fehler beim Senden.');

            return;
        }

        $this->sent = true;
    }

    public function verify(MagicLinkService $service): void
    {
        try {
            $service->consumeCode($this->email, $this->code, request());
        } catch (ValidationException $e) {
            $this->addError('code', $e->errors()['code'][0] ?? $e->errors()['token'][0] ?? 'Der Code stimmt nicht.');

            return;
        }

        session()->regenerate();
        if (session()->pull('first_login')) {
            $this->redirectRoute('welcome');
        } else {
            $this->redirectIntended(route('home'));
        }
    }

    /** Back to the address field, e.g. after a typo */
    public function restart(): void
    {
        $this->reset('sent', 'code');
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.auth.login-form');
    }
}
