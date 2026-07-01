<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'username' => $this->usernameRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'username' => $input['username'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // Credit the referrer (by handle) captured from a ?ref= link. Points are
        // awarded to them only once this user verifies their email — see
        // App\Listeners\AwardReferralOnVerified.
        if ($ref = session()->pull('referral')) {
            $referrer = User::where('username', $ref)->first();

            if ($referrer && $referrer->id !== $user->id) {
                $user->forceFill(['referred_by_user_id' => $referrer->id])->save();
            }
        }

        return $user;
    }
}
