<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\MembershipTier;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Default attribute values — mirrors the DB default so a freshly-created
     * (not-yet-reloaded) model already reports the free tier.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'membership_tier' => 'free',
        'is_admin' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'membership_tier' => MembershipTier::class,
            'is_admin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Configured operator emails become admins automatically on sign-up.
        static::creating(function (User $user) {
            $admins = (array) config('membership.admins', []);
            if ($user->email && in_array(strtolower($user->email), array_map('strtolower', $admins), true)) {
                $user->is_admin = true;
            }
        });
    }

    public function isPremium(): bool
    {
        return $this->is_admin || $this->membership_tier === MembershipTier::Premium;
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /** @return HasMany<CollectionItem, $this> */
    public function collectionItems(): HasMany
    {
        return $this->hasMany(CollectionItem::class);
    }

    /** @return HasMany<ScanUsage, $this> */
    public function scanUsages(): HasMany
    {
        return $this->hasMany(ScanUsage::class);
    }
}
