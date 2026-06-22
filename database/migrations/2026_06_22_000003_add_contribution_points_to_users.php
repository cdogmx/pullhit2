<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalized lifetime contribution points on the user — kept in sync by the
 * AwardPoints action so the leaderboard + a user's level read without a
 * per-request SUM over the contributions ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('contribution_points')->default(0)->after('membership_tier');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('contribution_points');
        });
    }
};
