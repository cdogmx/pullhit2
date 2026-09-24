<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each grader's published centering tolerance allowed, as it stood when
 * the run was made.
 *
 * Stored rather than recomputed, because the tolerances in config can change —
 * a company revises a standard, or one of the empty entries gets filled in —
 * and a saved run should keep saying what it said at the time. Recomputing
 * would silently rewrite history every time the config moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_predictions', function (Blueprint $table) {
            $table->json('centering_standards')->nullable()->after('observed');
        });
    }

    public function down(): void
    {
        Schema::table('grade_predictions', function (Blueprint $table) {
            $table->dropColumn('centering_standards');
        });
    }
};
