<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Promote an existing user to admin (unlimited access). New sign-ups whose email
 * is listed in config('membership.admins') are promoted automatically.
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'users:make-admin {email}';

    protected $description = 'Grant admin (unlimited access) to a user by email';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email {$email}.");

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true])->save();
        $this->info("{$email} is now an admin.");

        return self::SUCCESS;
    }
}
