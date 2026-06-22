<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only points ledger — one row each time a user's contribution is
 * accepted (an approved edit, an accepted missing-card/set report, …). Lifetime
 * totals drive levels; rows in the current month are a user's giveaway entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');             // App\Enums\ContributionType
            $table->unsignedInteger('points');
            $table->nullableMorphs('subject');  // the accepted source record
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributions');
    }
};
