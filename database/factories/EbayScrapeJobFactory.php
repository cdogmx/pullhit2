<?php

namespace Database\Factories;

use App\Models\CatalogItem;
use App\Models\EbayScrapeJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EbayScrapeJob> */
class EbayScrapeJobFactory extends Factory
{
    protected $model = EbayScrapeJob::class;

    public function definition(): array
    {
        return [
            'catalog_item_id' => CatalogItem::factory(),
            'url' => 'https://www.ebay.com/sch/i.html?_nkw=Charizard&LH_Sold=1&LH_Complete=1',
            'status' => EbayScrapeJob::STATUS_PENDING,
            'priority' => 0,
            'attempts' => 0,
        ];
    }
}
