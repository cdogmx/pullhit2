<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grading services (PSA, BGS, CGC, …). Grade itself is a state of a physical
 * instance (collection_items / sale_observations / listings) — never on the
 * abstract catalog_item (§4.3). This table just describes each service's scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_companies', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedTinyInteger('scale_max')->default(10);
            $table->boolean('supports_half_grades')->default(false);
            $table->boolean('supports_subgrades')->default(false);
            $table->boolean('supports_pristine_black_label')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_companies');
    }
};
