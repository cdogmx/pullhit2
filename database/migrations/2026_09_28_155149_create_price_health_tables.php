<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for the nightly price checks to leave their results.
 *
 * Both passes printed to a console nobody reads. The divergence report is the
 * one measurement that has caught real damage from outside the system —
 * including a bug I introduced in the anchor, which the test suite could not see
 * — and the adjudicator's findings are worth more as a reviewable list than as
 * scrollback.
 *
 * Two tables because they answer different questions. The snapshot is a trend:
 * is the catalog getting better or worse. A finding is a single comp somebody
 * has to decide about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('compared');
            // The full distribution, so the shape can be re-drawn later even if
            // the buckets are redefined.
            $table->json('buckets');
            $table->unsignedInteger('over_2x');
            $table->unsignedInteger('under_half');
            $table->timestamps();
        });

        Schema::create('comp_adjudications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained()->cascadeOnDelete();
            // Nullable: the comp may be pruned by another pass before anyone
            // reviews this, and a finding pointing at nothing should read as
            // stale rather than break the page.
            $table->foreignId('sale_observation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reads_as_catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();

            $table->unsignedBigInteger('price');
            $table->text('title');
            // What the card's price was doing when this was flagged — the reason
            // the comp was looked at at all.
            $table->decimal('ratio', 8, 2)->nullable();

            // open -> somebody still has to decide. Applied means the comp was
            // removed; dismissed means the model was wrong and we said so.
            $table->string('status')->default('open')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // One finding per comp: a nightly re-run must not pile up duplicates
            // of a judgement nobody has acted on yet.
            $table->unique('sale_observation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_adjudications');
        Schema::dropIfExists('price_health_snapshots');
    }
};
