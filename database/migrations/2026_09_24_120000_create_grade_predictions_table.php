<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A prediction the bench made, and — when it comes back — the grade the card
 * actually got.
 *
 * The point is the second half. CenteringMeasurer's constant is fitted to one
 * cert and says so: "treat the constant as provisional and recalibrate it as
 * more certs arrive." Nothing collected those certs. A prediction saved beside
 * its real outcome is one anchor; a few dozen is a calibration set, and the
 * difference between a pipeline we believe and one we have checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What card this was, as far as anyone bothered to say. Free text
            // because a card being tested may not be in the catalog at all.
            $table->string('label')->nullable();
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();

            // The reading: per-side surface and centering, and the roll-up.
            $table->json('sides');
            $table->json('estimate');
            $table->json('observed');

            // Whether a person placed the guides or accepted what the model
            // proposed. Kept because it decides whether a row belongs in a
            // calibration set: a guide nobody checked is not a measurement,
            // and mixing those in would tune the constant against the vision
            // model's aim rather than against the card.
            $table->string('guides_source')->nullable();

            // What the grader actually said. Null until the card comes back.
            $table->string('actual_company')->nullable();
            $table->decimal('actual_grade', 4, 1)->nullable();
            $table->string('actual_cert')->nullable();
            // Their per-attribute sub-scores, where the report gives them —
            // the rows that can calibrate an attribute rather than a grade.
            $table->json('actual_subscores')->nullable();
            $table->timestamp('graded_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // The calibration query: everything with a real outcome, newest
            // first.
            $table->index(['actual_grade', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_predictions');
    }
};
