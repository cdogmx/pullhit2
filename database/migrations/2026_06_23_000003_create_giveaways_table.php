<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly community giveaways. Entries are NOT stored per-row — a user's entries
 * for a giveaway are the points they earned in its calendar month (the
 * contributions ledger), so the draw is always auditable from source. The draw
 * snapshots the winner + pool totals onto the row for history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giveaways', function (Blueprint $table) {
            $table->id();
            $table->string('period')->unique();        // 'YYYY-MM' the entries come from
            $table->string('title');
            $table->string('prize');
            $table->text('description')->nullable();
            $table->string('status')->default('open')->index(); // open | drawn
            $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('winner_entries')->nullable();
            $table->unsignedInteger('total_entries')->nullable();
            $table->unsignedInteger('entrant_count')->nullable();
            $table->timestamp('drawn_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giveaways');
    }
};
