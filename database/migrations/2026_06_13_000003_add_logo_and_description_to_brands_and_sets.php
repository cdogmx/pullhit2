<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_lines', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('name');
            $table->text('description')->nullable()->after('logo_path');
        });

        Schema::table('sets', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('name');
            $table->text('description')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('product_lines', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'description']);
        });

        Schema::table('sets', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'description']);
        });
    }
};
