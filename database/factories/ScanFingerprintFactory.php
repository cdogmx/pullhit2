<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use App\Models\ScanFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScanFingerprint>
 */
class ScanFingerprintFactory extends Factory
{
    protected $model = ScanFingerprint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_item_id' => CatalogItem::factory(),
            'phash' => $this->faker->regexify('[0-9a-f]{16}'),
            'confirmations' => 1,
            'last_user_id' => null,
        ];
    }
}
