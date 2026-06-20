<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily portfolio value + cost basis per user — written by `valuation:snapshot`.
 * Tiny (#users × days); powers the "portfolio value over time" chart. One row
 * per (user, day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('total_value_cents');
            $table->integer('cost_basis_cents');
            $table->unsignedInteger('card_count');
            $table->date('captured_on');
            $table->timestamps();

            $table->unique(['user_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_snapshots');
    }
};
