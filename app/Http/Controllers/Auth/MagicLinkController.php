<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\MagicLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MagicLinkController extends Controller
{
    public function __construct(private readonly MagicLinkService $magicLinkService) {}

    public function showForm(): View
    {
        return view('auth.login');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'max:254', 'email'],
        ], [
            'email.required' => 'Bitte gib deine E-Mail-Adresse ein.',
            'email.*' => 'Bitte gib eine gültige E-Mail-Adresse ein.',
        ]);

        $this->magicLinkService->sendLink($request->input('email'), $request);

        return redirect()->route('auth.magic-link.sent');
    }

    public function linkSent(): View
    {
        return view('auth.link-sent');
    }

    /**
     * Show the confirmation page for the magic link token.
     * Does NOT consume the token – prevents email link scanners from logging in the user.
     */
    public function showConfirm(string $token): View
    {
        return view('auth.confirm-link', ['token' => $token]);
    }

    /**
     * Consume the magic link token and log in the user.
     */
    public function consumeToken(Request $request, string $token): RedirectResponse
    {
        try {
            $this->magicLinkService->consumeToken($token, $request);
        } catch (ValidationException $e) {
            return redirect()->route('auth.magic-link.form')
                ->withErrors(['email' => $e->errors()['token'][0] ?? 'Ungültiger Link.']);
        }

        $request->session()->regenerate();

        return $request->session()->pull('first_login') ? redirect()->route('welcome') : redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('auth.magic-link.form');
    }
}
