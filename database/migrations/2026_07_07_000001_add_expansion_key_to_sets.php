<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links sets that are the SAME expansion across languages (e.g. JP "Ninja
 * Spinner" <-> EN "Chaos Rising"), which share no code/name/set_family. Sets
 * with a matching expansion_key are cross-language equivalents; used to link a
 * card to its other-language printings where the per-set numbering aligns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->string('expansion_key', 64)->nullable()->after('set_family')->index();
        });
    }

    public function down(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->dropColumn('expansion_key');
        });
    }
};
