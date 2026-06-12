<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user-defined grouping for holdings (binder/folder/tag) — collectors organize
 * their collections this way, and importers (PriceCharting "folder", Collectr
 * binders) carry it. Nullable free-text for now; can graduate to a tags table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            $table->string('folder')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('collection_items', function (Blueprint $table) {
            $table->dropColumn('folder');
        });
    }
};
