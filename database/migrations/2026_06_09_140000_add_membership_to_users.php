<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership tier on users (Phase 4). Collection + portfolio are free for all;
 * the tier's first real benefit is scan volume (metered in Phase 4b). Billing
 * (Stripe/Cashier) is a later phase — premium is a plain flag for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('membership_tier')->default('free')->index()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('membership_tier'));
    }
};
