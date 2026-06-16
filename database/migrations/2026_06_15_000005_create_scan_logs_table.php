<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-scan audit log (one row per scan request) for user history and admin
 * record-keeping: how many cards were identified, how many hit the AI (and cost
 * a credit) vs the recognition cache, and how many purchased credits were spent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 16);                          // single | bulk
            $table->unsignedInteger('cards')->default(0);        // total identified
            $table->unsignedInteger('ai_reads')->default(0);     // metered (cost a credit)
            $table->unsignedInteger('cache_hits')->default(0);   // recognized, free
            $table->unsignedInteger('credits_spent')->default(0);// from purchased credits
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_logs');
    }
};
