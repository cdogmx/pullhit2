<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reshape stock alerts from one-Amazon-ASIN-per-row into a product with many
 * retailer links: a `tracked_product` (optionally tied to a catalog item) holds
 * the target price; each `retailer_link` is one store (Amazon, Walmart, …) with
 * its own polled stock/price state. Existing Amazon alerts are carried over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('image_url')->nullable();
            $table->unsignedInteger('target_price'); // cents
            $table->string('currency', 8)->default('USD');
            $table->unsignedSmallInteger('check_interval_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('retailer_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_product_id')->constrained('tracked_products')->cascadeOnDelete();
            $table->string('retailer', 32);
            $table->text('url');
            $table->string('external_id')->nullable();
            $table->boolean('is_active')->default(true);

            // Per-link polled state (drives rising-edge, per-retailer alerting).
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('last_price')->nullable();
            $table->boolean('last_in_stock')->default(false);
            $table->string('last_status')->nullable();
            $table->string('last_title')->nullable();
            $table->string('last_image')->nullable();
            $table->string('last_error')->nullable();
            $table->boolean('last_qualified')->default(false);
            $table->timestamp('last_tweeted_at')->nullable();
            $table->string('last_tweet_id')->nullable();

            $table->timestamps();
            $table->index(['tracked_product_id', 'retailer']);
        });

        // Carry over existing Amazon alerts (if the old table is present).
        if (Schema::hasTable('stock_alerts')) {
            foreach (DB::table('stock_alerts')->get() as $a) {
                $productId = DB::table('tracked_products')->insertGetId([
                    'catalog_item_id' => null,
                    'name' => $a->label,
                    'image_url' => null,
                    'target_price' => $a->target_price,
                    'currency' => $a->currency,
                    'check_interval_minutes' => $a->check_interval_minutes,
                    'is_active' => $a->is_active,
                    'created_at' => $a->created_at,
                    'updated_at' => $a->updated_at,
                ]);

                $domain = $a->domain ?: 'com';

                DB::table('retailer_links')->insert([
                    'tracked_product_id' => $productId,
                    'retailer' => 'amazon',
                    'url' => "https://www.amazon.{$domain}/dp/{$a->asin}",
                    'external_id' => $a->asin,
                    'is_active' => $a->is_active,
                    'last_checked_at' => $a->last_checked_at,
                    'last_price' => $a->last_price,
                    'last_in_stock' => $a->last_in_stock,
                    'last_status' => $a->last_status,
                    'last_title' => $a->last_title,
                    'last_image' => null,
                    'last_error' => $a->last_error,
                    'last_qualified' => $a->last_qualified,
                    'last_tweeted_at' => $a->last_tweeted_at,
                    'last_tweet_id' => $a->last_tweet_id,
                    'created_at' => $a->created_at,
                    'updated_at' => $a->updated_at,
                ]);
            }

            Schema::dropIfExists('stock_alerts');
        }
    }

    public function down(): void
    {
        // Minimal restore of the old shape (data is not migrated back).
        if (! Schema::hasTable('stock_alerts')) {
            Schema::create('stock_alerts', function (Blueprint $table) {
                $table->id();
                $table->string('label')->nullable();
                $table->string('asin', 16)->index();
                $table->string('domain', 8)->default('com');
                $table->string('geo_location')->nullable();
                $table->unsignedInteger('target_price');
                $table->string('currency', 8)->default('USD');
                $table->unsignedSmallInteger('check_interval_minutes')->default(15);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_checked_at')->nullable();
                $table->unsignedInteger('last_price')->nullable();
                $table->boolean('last_in_stock')->default(false);
                $table->string('last_status')->nullable();
                $table->string('last_title')->nullable();
                $table->string('last_error')->nullable();
                $table->boolean('last_qualified')->default(false);
                $table->timestamp('last_tweeted_at')->nullable();
                $table->string('last_tweet_id')->nullable();
                $table->timestamps();
            });
        }

        Schema::dropIfExists('retailer_links');
        Schema::dropIfExists('tracked_products');
    }
};
