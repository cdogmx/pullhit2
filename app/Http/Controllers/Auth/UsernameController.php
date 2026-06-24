<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One-time "choose your username" step for accounts created via social login
 * (which arrive without a username). Enforced by the RequireUsername gate.
 */
class UsernameController extends Controller
{
    use ProfileValidationRules;

    public function edit(Request $request): Response|RedirectResponse
    {
        // Already has one (e.g. a password user) — nothing to do here.
        if ($request->user()->username) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('auth/choose-username', [
            'suggestion' => $this->suggest($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => $this->usernameRules($request->user()->id),
        ]);

        $request->user()->forceFill(['username' => $data['username']])->save();

        return redirect()->intended(route('dashboard'));
    }

    /** A starting suggestion from the user's name (or email local-part). */
    private function suggest(User $user): string
    {
        $base = Str::of($user->name ?: Str::before($user->email, '@'))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9_-]+/', '-')
            ->trim('-')
            ->limit(30, '');

        return Str::length($base) >= 3 ? (string) $base : '';
    }
}
