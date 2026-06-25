<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the eBay gallery thumbnail for each unmatched listing so an admin can
 * eyeball what the sale actually is when assigning it to a card. Nullable —
 * older rows (and any listing whose markup we couldn't read an image from) stay
 * null and fall back to the listing link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ebay_sweep_misses', function (Blueprint $table) {
            $table->string('image_url', 500)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('ebay_sweep_misses', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
