<?php

use App\Models\ProductLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-series metadata (currently a logo) keyed by product line + the
 * series NAME — because "series" is just the sets' shared `series` string, not
 * its own entity. A row here overrides the auto-picked card thumbnail on the
 * series browse tile. Absent = fall back to a representative card image.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ProductLine::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->timestamps();

            $table->unique(['product_line_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_meta');
    }
};
