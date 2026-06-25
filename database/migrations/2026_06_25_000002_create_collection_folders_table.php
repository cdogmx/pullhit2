<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata for the free-text "folder" sub-groupings within a collection: a slug
 * (for a shareable link) and a per-folder public/private flag, so a single folder
 * can be shared without exposing the whole collection. Holdings still reference
 * their folder by name; this row is keyed by (collection_id, name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_public')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['collection_id', 'name']);
            $table->unique(['collection_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_folders');
    }
};
