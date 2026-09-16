<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A saved price race. See the migration for why sources are a spec rather than
 * a resolved list of cards.
 */
class PriceRace extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'slug', 'description', 'sources', 'options', 'is_public'];

    protected function casts(): array
    {
        return [
            'sources' => 'array',
            'options' => 'array',
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PriceRace $race) {
            if (empty($race->slug)) {
                $race->slug = $race->uniqueSlug();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isEditableBy(?User $user): bool
    {
        return $user !== null && $this->user_id !== null && $this->user_id === $user->id;
    }

    public function isVisibleTo(?User $user): bool
    {
        return $this->is_public || $this->isEditableBy($user);
    }

    /** A slug nobody else holds — races are shared by URL. */
    private function uniqueSlug(): string
    {
        $base = Str::slug($this->name) ?: 'race';
        $slug = $base;
        $n = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
