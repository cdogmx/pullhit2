<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Was the detection correct?" signal for each scanned card. Drives two things:
 * fixing the recognition cache (a wrong cache hit demotes/removes that
 * fingerprint association) and reviewing where the AI read missed (so prompts /
 * matching can be tuned from real results).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16);                 // cache | vision
            $table->char('phash', 16)->nullable();         // the scanned image's hash
            $table->boolean('was_correct');
            // What the scanner asserted (name/number/set), for AI-miss review.
            $table->json('identified')->nullable();
            // The top match shown, and the user's correction (when they fixed it).
            $table->foreignId('detected_catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->foreignId('corrected_catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->timestamps();

            $table->index(['source', 'was_correct']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_feedback');
    }
};
