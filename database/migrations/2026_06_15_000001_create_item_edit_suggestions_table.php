<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-submitted corrections to a catalog item, held for admin review. `changes`
 * holds only the proposed field overrides (name/number + attribute facets);
 * approving applies them to the catalog item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_edit_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            $table->json('changes');                 // {field: proposed value}
            $table->text('note')->nullable();        // submitter's explanation
            $table->string('status', 12)->default('pending')->index(); // pending|approved|rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['catalog_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_edit_suggestions');
    }
};
