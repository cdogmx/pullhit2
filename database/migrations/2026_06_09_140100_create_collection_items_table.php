<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's owned copies of a catalog item, at a specific priced state (§4.5).
 * One row per (user, catalog_item, condition, grading_company, grade); cost
 * basis lives in acquisition_lots. `is_for_sale` is reserved for the future
 * marketplace. No money columns here — value is read from market_values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->string('condition')->nullable();
            $table->foreignId('grading_company_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('grade', 3, 1)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_for_sale')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'catalog_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_items');
    }
};
