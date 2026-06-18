<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A durable ledger of money movements from Dodo Payments. Written only by the
 * billing webhook (the single seam that hears from the provider) — one row per
 * payment / refund event. The user's *current* state still lives on the users
 * row; this table is the history both the user and admin transaction views read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_transactions', function (Blueprint $table) {
            $table->id();
            // Nullable + nullOnDelete so a deleted user doesn't erase the financial record.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // What kind of purchase: subscription | credits | refund | other.
            $table->string('type')->default('other');
            // Settlement state: succeeded | failed | refunded.
            $table->string('status')->default('succeeded');
            // Raw provider event name (e.g. payment.succeeded) for auditing.
            $table->string('event_type')->nullable();

            // Money in the currency's minor units (cents), as Dodo reports it.
            $table->integer('amount')->nullable();
            $table->string('currency', 3)->nullable();

            // Purchase specifics — set the relevant one for the type.
            $table->string('tier')->nullable();
            $table->unsignedInteger('credits')->nullable();
            $table->string('description')->nullable();

            // Provider identifiers for reconciliation.
            $table->string('dodo_payment_id')->nullable()->index();
            $table->string('dodo_subscription_id')->nullable();

            // Full event data for forensic review (never trusted for display logic).
            $table->json('payload')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_transactions');
    }
};
