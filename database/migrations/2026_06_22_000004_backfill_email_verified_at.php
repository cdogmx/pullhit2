<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grandfather existing users in. We're switching on MustVerifyEmail, which
     * would otherwise gate every current account at the "verify your email"
     * screen. Mark anyone already in the system as verified so only NEW
     * signups have to confirm. (Carbon now() rather than a raw SQL function
     * keeps this portable across MySQL and the SQLite test database.)
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Irreversible: we can't know which users were unverified before the
     * backfill, and un-verifying everyone would lock out legitimate accounts.
     */
    public function down(): void
    {
        //
    }
};
