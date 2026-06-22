<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-submitted "this card / set is missing" reports, awaiting admin review.
 * On approval the submitter earns contribution points (and an admin adds the
 * card/set with the existing catalog tools).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);                 // card | set
            $table->string('name');                    // card or set name
            $table->json('details')->nullable();       // number, set, brand, language, notes, image_url
            $table->string('status', 12)->default('pending')->index(); // pending|approved|rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_reports');
    }
};
