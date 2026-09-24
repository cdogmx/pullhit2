<?php

namespace App\Models;

use Database\Factories\GradePredictionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One run of the grading bench, and the grade the card really got.
 *
 * @property array<string, mixed> $sides
 * @property array<string, mixed> $estimate
 */
class GradePrediction extends Model
{
    /** @use HasFactory<GradePredictionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'label', 'catalog_item_id',
        'sides', 'estimate', 'observed', 'guides_source',
        'actual_company', 'actual_grade', 'actual_cert', 'actual_subscores',
        'graded_at', 'notes', 'share_token',
    ];

    protected function casts(): array
    {
        return [
            'sides' => 'array',
            'estimate' => 'array',
            'observed' => 'array',
            'actual_subscores' => 'array',
            'actual_grade' => 'float',
            'graded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /** Start sharing this reading, or return the link it already has. */
    public function share(): string
    {
        if ($this->share_token === null) {
            // Long and random rather than sequential: the link IS the
            // permission, so it has to be unguessable.
            $this->forceFill(['share_token' => Str::random(32)])->save();
        }

        return $this->shareUrl();
    }

    public function unshare(): void
    {
        // Nulled, not blanked — an old link stops resolving rather than
        // resolving to something empty.
        $this->forceFill(['share_token' => null])->save();
    }

    public function shareUrl(): string
    {
        return url('/grade-report/'.$this->share_token);
    }

    /**
     * The reading, as somebody who was not here would need it.
     *
     * Not the whole row — the notes are the owner's and who ran it is nobody
     * else's business — but everything needed to argue with the result IS here.
     * A link that shows a number and hides how it was reached cannot be
     * debugged from, and a bench nobody can argue with is a bench that stops
     * improving. Specular range, frame count and canvas size are how you tell a
     * bad capture from a bad card, and none of them is private.
     *
     * @return array<string, mixed>
     */
    public function toShared(): array
    {
        return [
            'label' => $this->label,
            'created_at' => $this->created_at?->toDateString(),
            'estimate' => $this->estimate,
            'observed' => $this->observed,
            'guides_source' => $this->guides_source,
            'sides' => collect($this->sides)->map(fn ($side) => [
                'usable' => $side['usable'] ?? null,
                'surface_assessable' => $side['surface_assessable'] ?? null,
                'surface' => $side['surface'] ?? null,
                'centering' => $side['centering'] ?? null,
                // The capture, so a poor reading can be traced to poor photos.
                'frames_used' => $side['frames_used'] ?? null,
                'specular_range' => $side['specular_range'] ?? null,
                'canvas' => $side['canvas'] ?? null,
                // The pictures, and where the guide sat on them. A centering
                // figure is only as good as the border it was measured to, and
                // this is the only way to see whether it was.
                'images' => $side['stored_images'] ?? [],
                'guide' => $side['guide'] ?? null,
            ])->all(),
            'actual_company' => $this->actual_company,
            'actual_grade' => $this->actual_grade,
            'actual_cert' => $this->actual_cert,
            'probability_of_actual' => $this->probabilityOfActual(),
        ];
    }

    /**
     * Rows that can actually calibrate something.
     *
     * A real outcome AND guides a person placed. An accepted AI guide is not a
     * measurement of the card — it is a measurement of the model's aim — and
     * tuning the centering constant against those would fit the wrong thing.
     *
     * @param  Builder<GradePrediction>  $query
     */
    public function scopeCalibratable(Builder $query): void
    {
        $query->whereNotNull('actual_grade')
            ->whereIn('guides_source', ['manual', 'ai-adjusted']);
    }

    /** The probability we gave the grade the card actually got, if we can tell. */
    public function probabilityOfActual(): ?float
    {
        if ($this->actual_grade === null) {
            return null;
        }

        $probs = $this->estimate['probs'] ?? [];
        // Reports write 10, the distribution is keyed "10"; a half grade has no
        // bucket of its own and falls to "other" with everything else we did
        // not price.
        $key = rtrim(rtrim(number_format($this->actual_grade, 1, '.', ''), '0'), '.');

        return isset($probs[$key]) ? (float) $probs[$key] : null;
    }
}
