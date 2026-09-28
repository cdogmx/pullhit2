<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The listing a finding is about, so a reviewer can open it.
 *
 * Copied onto the finding rather than read through the comp, for the same reason
 * the title is: applying a finding DELETES the observation, and a decided
 * finding still has to show what it was about. It is also how somebody checks
 * the model's reading against the actual listing before trusting the next one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comp_adjudications', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('comp_adjudications', fn (Blueprint $table) => $table->dropColumn('url'));
    }
};
