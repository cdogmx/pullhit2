<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost-basis detail per acquisition of a collection item (§9). Money fields are
 * integer minor units (cents). The sum of lots is the item's cost basis;
 * realized P&L (FIFO/LIFO/spec-ID) is deferred until selling exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_cost');   // cents
            $table->unsignedInteger('fees')->default(0); // cents
            $table->date('acquired_at');
            $table->string('source')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_lots');
    }
};
