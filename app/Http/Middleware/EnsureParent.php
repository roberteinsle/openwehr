<?php

namespace App\Http\Middleware;

use App\Services\ParentAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Eltern-Bereich: an Erziehungsberechtigte(r) signed in with the guard "guardian", or a Kamerad whose
 * address is stored as guardian of an active child. Guests go to the login, everyone else gets a 404.
 */
class EnsureParent
{
    public function handle(Request $request, Closure $next): Response
    {
        $parents = app(ParentAccess::class);
        if ($parents->hasChildren()) {
            return $next($request);
        }

        if (Auth::guard('guardian')->check()) {
            // The last child left or was set inactive: the parent's access ends with it
            Auth::guard('guardian')->logout();

            return redirect()->route('auth.magic-link.form')->withErrors(['email' => 'Für diese Adresse gibt es keinen Zugang mehr.']);
        }

        abort_if(Auth::guard('web')->check(), 404);

        return redirect()->route('auth.magic-link.form');
    }
}
