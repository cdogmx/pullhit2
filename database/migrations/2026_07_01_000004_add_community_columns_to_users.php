<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns backing the new point-earning loops: the daily check-in streak state
 * and who referred each user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('last_checkin_on')->nullable();
            $table->unsignedInteger('checkin_streak')->default(0);
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropColumn(['last_checkin_on', 'checkin_streak']);
        });
    }
};
