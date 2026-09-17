<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What eBay calls this set.
 *
 * eBay files every card in "CCG Individual Cards" under a structured Set aspect,
 * and filtering on it is worth more than any keyword: a search for the 30th
 * Celebration's Sylveon returns 126 listings on keywords alone and 22 with the
 * Set pinned — the other 104 being a different Sylveon from Celebrations, a set
 * from 2021 that shares almost every word.
 *
 * It needs storing because the names differ. We call it "30th Celebration";
 * eBay calls it "30th Anniversary Edition". Neither is wrong and no rule
 * derives one from the other, so it is learned from eBay and written down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->string('ebay_set')->nullable()->after('code');
            $table->timestamp('ebay_set_learned_at')->nullable()->after('ebay_set');
        });
    }

    public function down(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->dropColumn(['ebay_set', 'ebay_set_learned_at']);
        });
    }
};
