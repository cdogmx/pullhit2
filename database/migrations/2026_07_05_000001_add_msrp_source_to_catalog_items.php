<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for a sealed product's MSRP: where the figure came from (a cited
 * source URL when web-sourced, or 'admin' when hand-entered). Kept so a sourced
 * MSRP stays auditable — MSRPs are a defensible fact, not a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('msrp_source', 1024)->nullable()->after('msrp');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('msrp_source');
        });
    }
};
