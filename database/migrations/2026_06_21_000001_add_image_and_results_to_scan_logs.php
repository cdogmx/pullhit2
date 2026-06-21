<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turn the scan audit log into a user-facing scan history: keep a thumbnail of
 * the scanned photo and a lightweight snapshot of what was detected (the AI read
 * + the top catalog match per card). Both are best-effort and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_logs', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('mode');
            $table->json('results')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('scan_logs', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'results']);
        });
    }
};
