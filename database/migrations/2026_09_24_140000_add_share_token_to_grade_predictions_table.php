<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An unguessable link for one prediction.
 *
 * Nullable and unique: a prediction is private until somebody deliberately
 * shares it, and unsharing sets this back to null rather than leaving a link
 * that still resolves. The bench itself stays admin-only — this shares one
 * reading, not the tool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_predictions', function (Blueprint $table) {
            $table->string('share_token', 32)->nullable()->unique()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('grade_predictions', function (Blueprint $table) {
            $table->dropColumn('share_token');
        });
    }
};
