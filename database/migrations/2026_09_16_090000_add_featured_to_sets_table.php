<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A set given its own spot on the home page, for a while.
 *
 * A release everyone is opening is the reason people visit that week, and it is
 * buried in "Trending" behind cards with years of accumulated views. Featuring
 * it is a merchandising decision with a shelf life — hence a date rather than a
 * flag, so a set that stopped being news drops off by itself instead of sitting
 * on the front page until somebody remembers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->timestamp('featured_until')->nullable()->after('refresh_boost_until');
            $table->string('featured_blurb')->nullable()->after('featured_until');
        });
    }

    public function down(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->dropColumn(['featured_until', 'featured_blurb']);
        });
    }
};
