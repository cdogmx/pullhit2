<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-set, per-rarity pack odds — "how often does a booster pack yield a card of
 * this rarity". Sourced by AI web search (with a citation) and used to model a
 * sealed product's rip expected value. One row per (set, rarity).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('set_pull_odds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_id')->constrained()->cascadeOnDelete();
            // Matches catalog_items.attributes->rarity exactly, so odds join to cards.
            $table->string('rarity');
            // Probability (0..1) that a single pack yields a card of this rarity.
            $table->decimal('per_pack_prob', 8, 6);
            // Provenance: where the number came from + how confident we are.
            $table->string('method')->default('ai_search');
            $table->string('source')->nullable();      // citation URL
            $table->string('note')->nullable();        // e.g. "~1 in 12 packs"
            $table->decimal('confidence', 3, 2)->nullable(); // 0..1
            $table->timestamps();

            $table->unique(['set_id', 'rarity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('set_pull_odds');
    }
};
