<?php

namespace App\Models;

use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A named collection owned by a user. Holdings (collection_items) belong to one
 * collection. Every user has exactly one `is_default` collection; the number of
 * additional collections is gated by membership tier.
 */
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'is_public',
        'is_default',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CollectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CollectionItem::class);
    }

    /** @return HasMany<CollectionFolder, $this> */
    public function folders(): HasMany
    {
        return $this->hasMany(CollectionFolder::class);
    }

    /**
     * Get (or lazily create) the shareable metadata row for a folder name in this
     * collection, so it always has a slug + a visibility flag for the UI/links.
     */
    public function ensureFolder(string $name): CollectionFolder
    {
        $name = trim($name);

        return $this->folders()->firstOrCreate(
            ['name' => $name],
            ['slug' => $this->uniqueFolderSlug($name)],
        );
    }

    private function uniqueFolderSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'folder';
        $slug = $base;
        $n = 1;

        while ($this->folders()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$n;
        }

        return $slug;
    }
}
