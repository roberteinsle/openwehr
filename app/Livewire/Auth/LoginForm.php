<?php

namespace App\Livewire\Auth;

use App\Services\MagicLinkService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class LoginForm extends Component
{
    public string $email = '';

    public bool $sent = false;

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

    public function render()
    {
        return view('livewire.auth.login-form');
    }
}
