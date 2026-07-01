<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI researcher writes a detailed, multi-source `note` (and occasionally a
 * long `source`) that overflows a VARCHAR(255). Widen both to TEXT so the full
 * sourcing rationale is preserved — it's the transparency behind each rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_pull_odds', function (Blueprint $table) {
            $table->text('note')->nullable()->change();
            $table->text('source')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('set_pull_odds', function (Blueprint $table) {
            $table->string('note')->nullable()->change();
            $table->string('source')->nullable()->change();
        });
    }
};
