<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * Social (OAuth) sign-in via Laravel Socialite. Provider-agnostic — Facebook is
 * live; Google works the moment its credentials are set (see config/services).
 *
 * Flow: redirect → provider → callback. On callback we link by provider id, then
 * by (provider-verified) email, otherwise create a fresh account. New accounts
 * have no username yet; the RequireUsername gate sends them to pick one.
 */
class SocialiteController extends Controller
{
    /** Providers we accept (also constrained on the route). */
    private const PROVIDERS = ['facebook', 'google'];

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        return Socialite::driver($provider)
            ->redirectUrl(route('oauth.callback', $provider))
            ->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        try {
            $oauth = Socialite::driver($provider)
                ->redirectUrl(route('oauth.callback', $provider))
                ->user();
        } catch (InvalidStateException) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Social login failed — please try again.']);
        }

        $email = $oauth->getEmail();

        if (! $email) {
            return redirect()->route('login')->withErrors([
                'email' => 'Your '.ucfirst($provider).' account didn’t share an email, so we can’t sign you in. Please register with an email and password instead.',
            ]);
        }

        Auth::login($this->resolveUser($provider, $oauth, $email), remember: true);

        // New users have no username yet — the gate redirects them to choose one.
        return redirect()->intended(route('dashboard'));
    }

    private function resolveUser(string $provider, SocialiteUser $oauth, string $email): User
    {
        // 1) Already linked to this exact provider account.
        $linked = User::where('provider', $provider)
            ->where('provider_id', $oauth->getId())
            ->first();

        if ($linked) {
            return $linked;
        }

        // 2) An existing account with the same email — link the provider to it.
        //    Safe because social-provider emails are verified.
        $existing = User::where('email', $email)->first();

        if ($existing) {
            $existing->forceFill([
                'provider' => $existing->provider ?: $provider,
                'provider_id' => $existing->provider_id ?: $oauth->getId(),
                'avatar_path' => $existing->avatar_path ?: $oauth->getAvatar(),
                'email_verified_at' => $existing->email_verified_at ?? now(),
            ])->save();

            return $existing;
        }

        // 3) Brand-new account. No username (chosen on the next screen); the email
        //    is provider-verified, so mark it and fire Verified to send the
        //    one-time welcome email (same listener as the email-verify flow).
        $user = new User();
        $user->forceFill([
            'name' => $oauth->getName() ?: ($oauth->getNickname() ?: 'Collector'),
            'email' => $email,
            'email_verified_at' => now(),
            'provider' => $provider,
            'provider_id' => $oauth->getId(),
            'avatar_path' => $oauth->getAvatar(),
            'password' => null,
        ])->save();

        event(new Verified($user));

        return $user;
    }
}
