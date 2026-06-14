<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit + review queue for the PriceCharting reconciliation. High-confidence
 * changes land here as `applied` (with enough payload to spot-check / revert);
 * low-confidence ones as `pending` for an admin to approve. Keyed on the
 * PriceCharting product so re-runs update in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_changes', function (Blueprint $table) {
            $table->id();
            $table->string('pc_id')->unique();
            $table->foreignId('set_id')->nullable()->index();
            $table->string('action');
            $table->string('reason');
            $table->string('confidence', 8);
            $table->string('status', 12)->index();          // applied | pending | skipped
            $table->foreignId('catalog_item_id')->nullable()->index(); // created/affected item
            $table->json('payload')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['set_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_changes');
    }
};
