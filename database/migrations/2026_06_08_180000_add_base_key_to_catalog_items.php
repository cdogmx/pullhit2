<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `base_key` — the card-grouping id shared by every printing/variant of the
 * same card (Charizard Normal/Holo/Reverse all share one base_key). Distinct from
 * `identity_hash`, which stays unique per printing. Lets reads list variants
 * individually or collapse them by base card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->char('base_key', 64)->nullable()->after('identity_hash')->index();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropIndex(['base_key']);
            $table->dropColumn('base_key');
        });
    }
};
