<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only raw market signal (§4.4). Source-agnostic: TCGplayer, eBay (when
 * partner access lands), own marketplace, etc. all write here. The valuation
 * engine consumes these; rows are never mutated except to flag outliers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();

            // Priced-state dimensions: raw condition, and/or graded company + grade.
            $table->string('condition')->nullable();          // App\Enums\Condition (null when graded-only)
            $table->foreignId('grading_company_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('grade', 3, 1)->nullable();        // 9, 9.5, 10
            $table->string('grade_label')->nullable();         // e.g. "Pristine", "Black Label"

            $table->string('venue');                           // App\Enums\Venue
            $table->unsignedInteger('price');                  // integer minor units (cents)
            $table->char('currency', 3)->default('USD');
            $table->dateTime('observed_at');
            $table->string('source_listing_id')->nullable();   // dedup across re-pulls
            $table->boolean('is_outlier')->default(false);     // set by engine (MAD/Hampel) — never deleted
            $table->json('raw')->nullable();                   // original payload for audit
            $table->timestamps();

            $table->index(
                ['catalog_item_id', 'condition', 'grading_company_id', 'grade', 'observed_at'],
                'sale_obs_priced_state_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_observations');
    }
};
