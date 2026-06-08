<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The value-bearing unit — generic across all verticals (§4.1). Vertical-specific
 * facets live in the JSON `attributes` column (described by the Vertical registry),
 * NOT as first-class columns. The only generated column is `language` (§13).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The generated-column expression is DB-specific: MySQL needs json_unquote
        // to return a plain string; sqlite's json_extract already returns one.
        $languageExpr = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "json_unquote(json_extract(`attributes`, '$.language'))",
            default => "json_extract(\"attributes\", '$.language')", // sqlite
        };

        Schema::create('catalog_items', function (Blueprint $table) use ($languageExpr) {
            $table->id();

            $table->foreignId('vertical_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('set_id')->nullable()->constrained()->nullOnDelete();

            $table->string('item_type'); // App\Enums\ItemType: single|sealed|lot|other
            $table->string('name');
            $table->string('number')->nullable()->index(); // collector/card number

            // All vertical-specific facets. Defined before the generated column
            // that derives from it.
            $table->json('attributes');

            // Generated, indexed facet — the one allowed generated column (§13).
            $table->string('language')->nullable()->virtualAs($languageExpr)->index();

            // sha256(vertical, product_line, set, canonical facets) — dedup key (§4.1).
            $table->char('identity_hash', 64)->unique();

            $table->string('primary_image_path')->nullable(); // S3 key
            // {tcgplayer_product_id, ptcgio_id, scryfall_id, ...}
            $table->json('external_ids')->nullable();

            $table->timestamps();

            $table->index(['set_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
