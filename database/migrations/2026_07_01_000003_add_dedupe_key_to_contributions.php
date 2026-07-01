<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional idempotency key on the points ledger — lets repeatable-but-capped
 * awards (per-day check-ins, per-fingerprint scan feedback) dedupe without a
 * model subject. One-time milestones keep using (user, type) with a null key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->string('dedupe_key')->nullable()->after('description');
            $table->index(['user_id', 'type', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'type', 'dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
