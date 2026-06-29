<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-curated watch list: poll an Amazon product (by ASIN) via Oxylabs and
 * tweet when it's in stock at or below a target price. State columns let the
 * checker fire only on the rising edge (newly qualifying) and self-throttle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('label')->nullable();
            $table->string('asin', 16)->index();
            $table->string('domain', 8)->default('com');
            $table->string('geo_location')->nullable();
            // Target + last-seen prices are stored in cents.
            $table->unsignedInteger('target_price');
            $table->string('currency', 8)->default('USD');
            $table->unsignedSmallInteger('check_interval_minutes')->default(15);
            $table->boolean('is_active')->default(true);

            // Evaluation state (drives rising-edge alerting + the admin view).
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('last_price')->nullable();
            $table->boolean('last_in_stock')->default(false);
            $table->string('last_status')->nullable();
            $table->string('last_title')->nullable();
            $table->string('last_error')->nullable();
            // True when the most recent check satisfied in-stock AND <= target.
            $table->boolean('last_qualified')->default(false);
            $table->timestamp('last_tweeted_at')->nullable();
            $table->string('last_tweet_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};
