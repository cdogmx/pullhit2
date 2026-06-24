<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            // Generated share/OG collage (top cards + prices), refreshed weekly.
            $table->string('og_image_path')->nullable()->after('logo_path');
            $table->timestamp('og_image_at')->nullable()->after('og_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->dropColumn(['og_image_path', 'og_image_at']);
        });
    }
};
