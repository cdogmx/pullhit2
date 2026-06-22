<?php

namespace App\Models;

use App\Enums\MembershipTier;
use App\Support\Community\Level;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
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

#[Fillable(['name', 'username', 'email', 'password', 'is_collection_public', 'is_wishlist_public'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
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
        'purchased_scan_credits' => 0,
        'contribution_points' => 0,
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
            'is_collection_public' => 'boolean',
            'is_wishlist_public' => 'boolean',
            'membership_renews_at' => 'datetime',
            'membership_cancel_scheduled' => 'boolean',
            'banned_at' => 'datetime',
            'contribution_points' => 'integer',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Non-critical notification types a user can opt out of, with the copy shown
     * on the settings page. Account-critical mail (verification, password reset,
     * receipts) is intentionally absent — it's always sent.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const NOTIFICATION_TYPES = [
        'wishlist_targets' => [
            'label' => 'Wishlist price alerts',
            'description' => 'Notify me when a wishlisted card drops to my target price.',
        ],
        'edit_reviews' => [
            'label' => 'Card edit updates',
            'description' => 'Notify me when an edit I suggested is approved or declined.',
        ],
    ];

    /**
     * Whether the user wants a given notification type. Unknown/unset keys
     * default to true (opt-out model).
     */
    public function wantsNotification(string $type): bool
    {
        return (bool) ($this->notification_preferences[$type] ?? true);
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
        return $this->is_admin || $this->membership_tier->isPaid();
    }

    /** Grant purchased scan credits (from a credit-pack purchase). */
    public function addScanCredits(int $credits): void
    {
        if ($credits > 0) {
            $this->increment('purchased_scan_credits', $credits);
        }
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /** Whether this account is banned (access denied by EnsureNotBanned). */
    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    /** @return HasMany<BillingTransaction, $this> */
    public function billingTransactions(): HasMany
    {
        return $this->hasMany(BillingTransaction::class)->latest();
    }

    /** @return HasMany<CollectionItem, $this> */
    public function collectionItems(): HasMany
    {
        return $this->hasMany(CollectionItem::class);
    }

    /** @return HasMany<Collection, $this> */
    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    /** The user's default collection, created on demand. */
    public function defaultCollection(): Collection
    {
        return $this->collections()->firstOrCreate(
            ['is_default' => true],
            ['name' => 'My Collection', 'slug' => 'my-collection', 'is_public' => (bool) $this->is_collection_public],
        );
    }

    /** @return HasMany<Wishlist, $this> */
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    /** The user's default wishlist (the heart-toggle target), created on demand. */
    public function defaultWishlist(): Wishlist
    {
        return $this->wishlists()->firstOrCreate(
            ['is_default' => true],
            ['name' => 'My Wishlist', 'slug' => 'my-wishlist', 'is_public' => (bool) $this->is_wishlist_public],
        );
    }

    /** @return HasMany<WishlistItem, $this> */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /** @return HasMany<ScanUsage, $this> */
    public function scanUsages(): HasMany
    {
        return $this->hasMany(ScanUsage::class);
    }

    /** @return HasMany<ScanLog, $this> */
    public function scanLogs(): HasMany
    {
        return $this->hasMany(ScanLog::class)->latest();
    }

    /** @return HasMany<Contribution, $this> */
    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    /** @return HasMany<CardReport, $this> */
    public function cardReports(): HasMany
    {
        return $this->hasMany(CardReport::class)->latest();
    }

    /** Level (name + progress) from lifetime contribution points. */
    public function level(): array
    {
        return Level::for((int) $this->contribution_points);
    }

    /** Points earned this calendar month — the user's giveaway entries. */
    public function monthlyEntries(): int
    {
        return (int) $this->contributions()
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('points');
    }

    /** @return HasMany<PortfolioSnapshot, $this> */
    public function portfolioSnapshots(): HasMany
    {
        return $this->hasMany(PortfolioSnapshot::class);
    }
}
