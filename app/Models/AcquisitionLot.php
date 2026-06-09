<?php

namespace App\Models;

use Database\Factories\AcquisitionLotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One acquisition of a collection item — the cost-basis record (§9). Money
 * fields are integer minor units (cents).
 */
#[Fillable([
    'collection_item_id',
    'quantity',
    'unit_cost',
    'fees',
    'acquired_at',
    'source',
])]
class AcquisitionLot extends Model
{
    /** @use HasFactory<AcquisitionLotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'fees' => 'integer',
            'acquired_at' => 'date',
        ];
    }

    /** @return BelongsTo<CollectionItem, $this> */
    public function collectionItem(): BelongsTo
    {
        return $this->belongsTo(CollectionItem::class);
    }
}
