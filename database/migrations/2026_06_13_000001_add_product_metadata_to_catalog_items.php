<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic product metadata for catalog items (esp. sealed products): MSRP (cents),
 * a per-product release date, and retailer "where to buy" links. Vertical-agnostic
 * and deliberately NOT part of the identity hash — they're metadata, not identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->unsignedBigInteger('msrp')->nullable()->after('primary_image_path');
            $table->date('released_at')->nullable()->after('msrp');
            $table->json('retailer_links')->nullable()->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn(['msrp', 'released_at', 'retailer_links']);
        });
    }
};
