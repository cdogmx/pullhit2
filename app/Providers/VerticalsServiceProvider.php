<?php

namespace App\Providers;

use App\Support\Verticals\Definitions\TcgVertical;
use App\Support\Verticals\VerticalRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Vertical registry: binds it as a singleton and registers the known
 * vertical definitions. Adding a vertical = register its definition here (plus
 * seed data + adapters) — no core table or code changes (§3).
 */
class VerticalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VerticalRegistry::class, function (): VerticalRegistry {
            $registry = new VerticalRegistry;
            $registry->register(TcgVertical::definition());

            return $registry;
        });
    }
}
