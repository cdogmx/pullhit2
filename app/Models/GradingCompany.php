<?php

namespace App\Models;

use Database\Factories\GradingCompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A grading service (PSA, BGS, CGC, …). Describes the service's scale; grade
 * itself lives on physical instances, never on catalog_items (§4.3).
 */
#[Fillable([
    'slug',
    'name',
    'scale_max',
    'supports_half_grades',
    'supports_subgrades',
    'supports_pristine_black_label',
])]
class GradingCompany extends Model
{
    /** @use HasFactory<GradingCompanyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'scale_max' => 'integer',
            'supports_half_grades' => 'boolean',
            'supports_subgrades' => 'boolean',
            'supports_pristine_black_label' => 'boolean',
        ];
    }
}
