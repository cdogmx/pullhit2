<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rows a scan created that nobody has confirmed yet.
 *
 * A scan that matches nothing is a dead end today: the card is real, the person
 * is holding it, and they cannot log it. Creating it outright is worse — the
 * identity hash is a function of the NAME, so a row made from a vision read that
 * says "Charizard EX" never matches the official import's "Charizard ex" and
 * becomes a permanent duplicate. A wrong number also pulls in comps for another
 * card, which is how a $4 card came to be priced at $53.
 *
 * So the row exists and is usable, but is quarantined from everything that would
 * spread a mistake: browse, search, pricing, comp ingest, the sitemap. Confirming
 * it clears the flag; rejecting it deletes the row.
 *
 * All three levels carry the flag, because a brand we do not hold yet arrives as
 * a product line and a set as well as a card. There is one vertical (`tcg`) and
 * the attribute schema hangs off it, so a new brand needs no registry change.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['catalog_items', 'sets', 'product_lines'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                // Indexed: every public listing filters on it, so it has to be
                // cheap rather than a scan over the whole catalog.
                $t->boolean('is_provisional')->default(false)->index();

                if ($table === 'catalog_items') {
                    // Who to credit, and what the scanner actually read. Kept on
                    // the row so a reviewer can judge it without hunting for the
                    // scan, and so the read can be compared with what it became.
                    $t->foreignId('provisional_by')->nullable()->constrained('users')->nullOnDelete();
                    $t->json('provisional_read')->nullable();
                    $t->timestamp('provisional_at')->nullable();
                    // How many separate scans landed on this row. Fifty people
                    // scanning the same card is the demand signal for what to
                    // confirm first.
                    $t->unsignedInteger('provisional_scans')->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('provisional_by');
            $t->dropColumn(['is_provisional', 'provisional_read', 'provisional_at', 'provisional_scans']);
        });

        foreach (['sets', 'product_lines'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('is_provisional'));
        }
    }
};
