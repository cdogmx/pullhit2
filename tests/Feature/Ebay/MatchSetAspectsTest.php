<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Ebay\EbayTaxonomy;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->line = ProductLine::factory()->create(['slug' => 'pokemon']);

    // eBay's vocabulary, as the taxonomy endpoint returns it.
    app()->instance(EbayTaxonomy::class, new class extends EbayTaxonomy
    {
        public function __construct() {}

        public function aspectValues(string $aspect, string $categoryId): array
        {
            return [
                'SV: Paldean Fates',
                'Sv08: Surging Sparks',
                'Sm-Burning Shadows',
                'EX Dragon',
                'Sword & Shield - Chilling Reign',
                '30th Anniversary Edition',
                'Celebrations',
                // Two eBay sets that reduce to the same string once the code in
                // front is stripped. Both are real; they are different sets.
                'EX Legend Maker',
                'SM-Legend Maker',
            ];
        }
    });

    $this->set = function (string $name) {
        $set = Set::factory()->for($this->line)->create(['name' => $name, 'slug' => Str::slug($name)]);
        CatalogItem::factory()->create([
            'product_line_id' => $this->line->id, 'set_id' => $set->id,
            'attributes' => ['language' => 'en'],
        ]);

        return $set;
    };
});

test('eBay writes the set code in front and we do not', function () {
    $fates = ($this->set)('Paldean Fates');
    $sparks = ($this->set)('Surging Sparks');
    $burning = ($this->set)('Burning Shadows');
    $dragon = ($this->set)('Dragon');
    $chilling = ($this->set)('Chilling Reign');

    $this->artisan('ebay:match-set-aspects', ['--execute' => true])->assertSuccessful();

    expect($fates->fresh()->ebay_set)->toBe('SV: Paldean Fates')
        ->and($sparks->fresh()->ebay_set)->toBe('Sv08: Surging Sparks')
        ->and($burning->fresh()->ebay_set)->toBe('Sm-Burning Shadows')
        ->and($dragon->fresh()->ebay_set)->toBe('EX Dragon')
        ->and($chilling->fresh()->ebay_set)->toBe('Sword & Shield - Chilling Reign');
});

test('a name no normalising reaches is left for a person', function () {
    // "30th Celebration" is "30th Anniversary Edition" to eBay, and nothing
    // derives one from the other. Guessing would have chosen "Celebrations",
    // a different set, and pinned the whole set's comps to it.
    $set = ($this->set)('30th Celebration');

    $this->artisan('ebay:match-set-aspects', ['--execute' => true])->assertSuccessful();

    expect($set->fresh()->ebay_set)->toBeNull();
});

test('a dry run writes nothing', function () {
    $set = ($this->set)('Paldean Fates');

    $this->artisan('ebay:match-set-aspects')->assertSuccessful();

    expect($set->fresh()->ebay_set)->toBeNull();
});

test('two eBay names that reduce alike are not chosen between', function () {
    // Stripping the code in front is what makes the match work, and it is also
    // what can make two different sets look like one. When it does, picking
    // either on a coin toss would pin a whole set's comps to the wrong one.
    $set = ($this->set)('Legend Maker');

    $this->artisan('ebay:match-set-aspects', ['--execute' => true])
        ->expectsOutputToContain('2 candidates')
        ->assertSuccessful();

    expect($set->fresh()->ebay_set)->toBeNull();
});

test('a set that already has an answer is left alone', function () {
    $set = ($this->set)('Paldean Fates');
    $set->forceFill(['ebay_set' => 'Something A Person Chose'])->save();

    $this->artisan('ebay:match-set-aspects', ['--execute' => true])->assertSuccessful();

    expect($set->fresh()->ebay_set)->toBe('Something A Person Chose');
});
