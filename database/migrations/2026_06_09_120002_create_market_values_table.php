<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Computed valuation snapshot the app/API reads (§4.4). One row per priced state
 * — (catalog_item, condition, grading_company, grade) — identified by a compact
 * `state_key` (e.g. "NM", "psa-10", "SEALED") so the unique index is reliable
 * even though condition/grade are nullable (NULLs don't compare equal in SQL).
 * Reads always come from here; never computed on the fly in a request (§13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();

            $table->string('state_key');                       // canonical priced-state id
            $table->string('condition')->nullable();
            $table->foreignId('grading_company_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('grade', 3, 1)->nullable();

            // Distribution, in integer minor units (cents) — never a single point.
            $table->unsignedInteger('median');
            $table->unsignedInteger('p25');
            $table->unsignedInteger('p75');
            $table->unsignedInteger('low');
            $table->unsignedInteger('high');

            $table->unsignedInteger('n_sales');
            $table->decimal('confidence', 4, 3);               // 0–1
            $table->unsignedInteger('half_life_days');         // velocity-aware window used
            $table->decimal('trend_30d', 6, 2)->nullable();    // % change
            $table->decimal('trend_90d', 6, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->dateTime('computed_at');
            $table->timestamps();

            $table->unique(['catalog_item_id', 'state_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_values');
    }
};
