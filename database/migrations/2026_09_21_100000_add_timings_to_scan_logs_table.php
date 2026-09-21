<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record how long a scan took, and where the time went.
 *
 * scan_logs already said what a scan produced — cards, AI reads, cache hits,
 * credits — but nothing about its duration, so there was no way to tell whether
 * a scan was slow, which phase was slow, or whether a change to the model or the
 * recognition cache helped. Every column is nullable: the rows written before
 * this migration have no timings and should not pretend to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_logs', function (Blueprint $table) {
            // The whole server-side scan, so a total is available even if the
            // phase breakdown changes shape later.
            $table->unsignedInteger('duration_ms')->nullable()->after('credits_spent');

            // Bulk only: locating the cards in the photo.
            $table->unsignedInteger('detect_ms')->nullable()->after('duration_ms');

            // The vision read itself — one call in single mode, one batched call
            // for every uncached crop in bulk.
            $table->unsignedInteger('identify_ms')->nullable()->after('detect_ms');

            // Cropping, perceptual hashing and the cache lookup: the work that
            // is supposed to make identify_ms unnecessary.
            $table->unsignedInteger('fingerprint_ms')->nullable()->after('identify_ms');

            // Turning what the vision read said into catalog items.
            $table->unsignedInteger('match_ms')->nullable()->after('fingerprint_ms');

            // The uploaded photo's size. Vision latency tracks payload size, and
            // the client downscales before upload — this is how we would notice
            // if it ever stopped.
            $table->unsignedInteger('image_bytes')->nullable()->after('match_ms');
        });
    }

    public function down(): void
    {
        Schema::table('scan_logs', function (Blueprint $table) {
            $table->dropColumn([
                'duration_ms', 'detect_ms', 'identify_ms',
                'fingerprint_ms', 'match_ms', 'image_bytes',
            ]);
        });
    }
};
