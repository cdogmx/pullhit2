<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user monthly scan meter (cards identified). One row per (user, period);
 * the free tier is capped so worst-case Claude vision cost stays under ~$1/mo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7); // YYYY-MM
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_usages');
    }
};
