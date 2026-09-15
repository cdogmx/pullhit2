<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A temporary, per-set price-refresh cadence.
 *
 * A set in its first week does not behave like the catalog around it: the whole
 * market is being discovered at once and a twelve-hour-old price is wrong by
 * lunchtime. The global view TTL is the right default for 71,000 cards and the
 * wrong one for the 150 that just landed.
 *
 * The boost carries its own expiry rather than a flag someone has to remember
 * to turn off — a permanent ten-minute TTL on a settled set is just a way to
 * spend the scrape budget on nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->unsignedSmallInteger('refresh_minutes')->nullable()->after('released_at');
            $table->timestamp('refresh_boost_until')->nullable()->after('refresh_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->dropColumn(['refresh_minutes', 'refresh_boost_until']);
        });
    }
};
