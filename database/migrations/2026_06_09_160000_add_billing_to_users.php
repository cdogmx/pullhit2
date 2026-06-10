<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dodo Payments subscription state on users. The tier itself stays in
 * membership_tier (the entitlements seam); these track the Dodo subscription so
 * webhooks can flip the tier and the billing page can show status. Set only by
 * the billing action/webhook, never mass-assigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('dodo_customer_id')->nullable()->after('is_admin');
            $table->string('dodo_subscription_id')->nullable()->after('dodo_customer_id');
            $table->dateTime('membership_renews_at')->nullable()->after('dodo_subscription_id');
            $table->boolean('membership_cancel_scheduled')->default(false)->after('membership_renews_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'dodo_customer_id',
                'dodo_subscription_id',
                'membership_renews_at',
                'membership_cancel_scheduled',
            ]);
        });
    }
};
