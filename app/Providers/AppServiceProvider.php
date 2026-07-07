<?php

namespace App\Providers;

use App\Listeners\AwardReferralOnVerified;
use App\Support\Scanning\IdentifierStrategy;
use App\Support\Scanning\PokemonIdentifierStrategy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The TCG vertical is the only scan identifier today (§3 seam).
        $this->app->bind(IdentifierStrategy::class, PokemonIdentifierStrategy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Throttle the public /api/v1 surface (per user, else per IP) so the
        // read endpoints the web app polls can't be bulk-scraped. Generous enough
        // for a real user (the values poll is ~15/min) but caps enumeration.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Credit a referrer once their invitee verifies their email.
        Event::listen(Verified::class, AwardReferralOnVerified::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(8)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
