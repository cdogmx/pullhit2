<?php

namespace Database\Factories;

use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionItem>
 */
class CollectionItemFactory extends Factory
{
    protected $model = CollectionItem::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'catalog_item_id' => CatalogItem::factory(),
            'condition' => Condition::NearMint,
            'grading_company_id' => null,
            'grade' => null,
            'quantity' => 1,
            'is_for_sale' => false,
            'notes' => null,
        ];
    }
}
