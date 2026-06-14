<?php

use App\Models\PricechartingProduct;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Pricecharting\PriceGuideParser;
use App\Support\Pricecharting\SetMapper;
use App\Support\Pricecharting\TagClassifier;

test('the tag classifier peels edition/variant and leaves the rest as finish', function () {
    expect(TagClassifier::classify('1st Edition'))->toMatchArray(['edition' => 'first_edition', 'variant' => null, 'finish' => null]);
    expect(TagClassifier::classify('Shadowless'))->toMatchArray(['edition' => 'shadowless']);
    expect(TagClassifier::classify('Reverse'))->toMatchArray(['variant' => 'reverse_holo']);
    expect(TagClassifier::classify('1st Edition Red Cheeks'))->toMatchArray(['edition' => 'first_edition', 'finish' => 'red_cheeks']);
    expect(TagClassifier::classify('Black Dot Error'))->toMatchArray(['edition' => null, 'finish' => 'black_dot_error']);
    expect(TagClassifier::classify(null))->toMatchArray(['edition' => null, 'variant' => null, 'finish' => null]);
});

test('the price-guide parser normalizes products, prices, and sealed flag', function () {
    $csv = implode("\n", [
        'id,console-name,product-name,loose-price,cib-price,new-price,graded-price,box-only-price,manual-only-price,bgs-10-price,sales-volume,tcg-id,release-date',
        '630417,Pokemon Base Set,Charizard #4,$380.56,,,$3175.04,,"$30,100.00",,1990,42382,1999-01-09',
        '715593,Pokemon Base Set,Charizard [1st Edition] #4,"$7,240.31",,,,,"$435,462.64",,313,,1999-01-09',
        '900001,Pokemon Base Set,Booster Box [1st Edition],"$350,000.00",,,,,,,5,,1999-01-09',
        '900002,Pokemon Surging Sparks,Pikachu [Reverse] #58,$1.50,,,,,,,99,,2024-11-08',
    ]);

    $rows = iterator_to_array((new PriceGuideParser)($csv));

    expect($rows)->toHaveCount(4);

    expect($rows[0])->toMatchArray([
        'card_name' => 'Charizard', 'number' => '4', 'edition' => null, 'is_sealed' => false,
        'price_ungraded' => 38056, 'price_psa10' => 3010000, 'tcg_id' => '42382',
    ]);
    expect($rows[1])->toMatchArray(['edition' => 'first_edition', 'price_psa10' => 43546264]);
    expect($rows[2])->toMatchArray(['card_name' => 'Booster Box', 'number' => null, 'edition' => 'first_edition', 'is_sealed' => true]);
    expect($rows[3])->toMatchArray(['card_name' => 'Pikachu', 'number' => '58', 'variant' => 'reverse_holo']);
});

test('the set mapper resolves console names (with overrides + accent folding)', function () {
    $line = ProductLine::factory()->for(Vertical::factory()->create(['slug' => 'tcg']))->create(['slug' => 'pokemon']);
    $base = Set::factory()->for($line)->create(['slug' => 'base', 'name' => 'Base', 'language' => 'en']);
    $jungle = Set::factory()->for($line)->create(['slug' => 'jungle', 'name' => 'Jungle', 'language' => 'en']);
    $go = Set::factory()->for($line)->create(['slug' => 'pokemon-go', 'name' => 'Pokémon GO', 'language' => 'en']);

    $mapper = new SetMapper;

    expect($mapper->resolve('Base Set', 'en'))->toBe($base->id)   // override
        ->and($mapper->resolve('Jungle', 'en'))->toBe($jungle->id) // exact
        ->and($mapper->resolve('Pokemon GO', 'en'))->toBe($go->id) // accent-folded
        ->and($mapper->resolve('Pokemon Promo', 'en'))->toBeNull(); // non-catalog
});

test('the import command maps Base Set products to our set', function () {
    $line = ProductLine::factory()->for(Vertical::factory()->create(['slug' => 'tcg']))->create(['slug' => 'pokemon']);
    $base = Set::factory()->for($line)->create(['slug' => 'base', 'name' => 'Base', 'language' => 'en']);

    $csv = implode("\n", [
        'id,console-name,product-name,loose-price,manual-only-price,sales-volume,release-date',
        '630417,Pokemon Base Set,Charizard #4,$380.56,"$30,100.00",1990,1999-01-09',
        '715593,Pokemon Base Set,Charizard [1st Edition] #4,"$7,240.31","$435,462.64",313,1999-01-09',
    ]);
    $path = tempnam(sys_get_temp_dir(), 'pc').'.csv';
    file_put_contents($path, $csv);

    $this->artisan('catalog:pricecharting-import', ['--path' => $path])->assertSuccessful();

    expect(PricechartingProduct::count())->toBe(2)
        ->and(PricechartingProduct::where('edition', 'first_edition')->value('set_id'))->toBe($base->id)
        ->and(PricechartingProduct::where('edition', 'first_edition')->value('price_psa10'))->toBe(43546264);

    @unlink($path);
});
