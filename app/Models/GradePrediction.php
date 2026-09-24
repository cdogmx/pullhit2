<?php

namespace App\Models;

use Database\Factories\GradePredictionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'graded_at', 'notes',
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
