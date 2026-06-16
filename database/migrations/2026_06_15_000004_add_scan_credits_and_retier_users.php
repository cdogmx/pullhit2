<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a persistent purchased-scan-credit balance (top-up packs that survive
 * past the monthly allowance) and migrates the old single `premium` tier to the
 * new mid tier `collector` (Free / Collector / Dealer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('purchased_scan_credits')->default(0)->after('membership_tier');
        });

        DB::table('users')->where('membership_tier', 'premium')->update(['membership_tier' => 'collector']);
    }

    public function down(): void
    {
        DB::table('users')->where('membership_tier', 'collector')->update(['membership_tier' => 'premium']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('purchased_scan_credits');
        });
    }
};
