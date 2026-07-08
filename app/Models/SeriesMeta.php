<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-series metadata (a logo) keyed by product line + series name. Series isn't
 * a real entity — it's the sets' shared `series` string — so this is a thin
 * side-table that lets a series carry its own browse-tile image.
 */
#[Fillable(['product_line_id', 'name', 'logo_path'])]
class SeriesMeta extends Model
{
    protected $table = 'series_meta';

    /** @return BelongsTo<ProductLine, $this> */
    public function productLine(): BelongsTo
    {
        return $this->belongsTo(ProductLine::class);
    }
}
