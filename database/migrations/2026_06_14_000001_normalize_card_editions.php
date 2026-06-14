<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits the conflated edition off the `variant` axis. Historically a handful of
 * WOTC holos were imported as variant="1st_edition" (losing their holo-ness and
 * with no Unlimited sibling). The schema now has a dedicated, variant-defining
 * `edition` facet, so move those to variant="holo" + edition="first_edition".
 *
 * Run `php artisan catalog:rehash` afterwards so their identity_hash reflects the
 * new edition facet. No-op on a fresh/test DB (no such rows). Not reversible
 * (later reconciliation also writes edition="first_edition", so we can't tell the
 * migrated rows apart on rollback).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('catalog_items')
            ->where('attributes->variant', '1st_edition')
            ->update([
                'attributes->variant' => 'holo',
                'attributes->edition' => 'first_edition',
            ]);
    }

    public function down(): void
    {
        // Intentionally irreversible — see class docblock.
    }
};
