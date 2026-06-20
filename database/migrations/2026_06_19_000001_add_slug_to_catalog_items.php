<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-card URL slug, unique within a set, so cards live at
 * /{brand}/{set}/{card-slug} instead of /catalog/{id}. Backfilled by
 * `php artisan cards:backfill-slugs`; set automatically for new cards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('number');
            // Lookups resolve a card by (set, slug); also the uniqueness scope.
            $table->index(['set_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex(['set_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
