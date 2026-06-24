<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public-profile personalization: a short bio, location, website, and social
 * handles (X / Instagram), shown on /u/{username}. All optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('bio')->nullable()->after('avatar_path');
            $table->string('location')->nullable()->after('bio');
            $table->string('website')->nullable()->after('location');
            $table->string('x_handle')->nullable()->after('website');
            $table->string('instagram_handle')->nullable()->after('x_handle');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bio', 'location', 'website', 'x_handle', 'instagram_handle']);
        });
    }
};
