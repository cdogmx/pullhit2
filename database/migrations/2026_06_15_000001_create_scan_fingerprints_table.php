<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A learned, AI-free recognition cache: each row links a perceptual hash (dHash)
 * of a real scanned card photo to the catalog item the user confirmed it was.
 * Future scans hash the new photo and look for a near match here first, so a
 * card that's been scanned before can be recognised without an AI vision call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->char('phash', 16)->index(); // 64-bit dHash as hex
            $table->unsignedInteger('confirmations')->default(1);
            $table->foreignId('last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One row per (image fingerprint → catalog item); repeats bump the count.
            $table->unique(['phash', 'catalog_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_fingerprints');
    }
};
