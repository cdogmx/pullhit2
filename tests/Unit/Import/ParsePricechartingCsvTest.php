<?php

use App\Actions\Import\ParsePricechartingCsv;

// Rows lifted from a real PriceCharting export to lock the format conventions.
const PC_CSV = <<<'CSV'
id,product-name,console-name,price-in-pennies,include-string,condition-string,sku,notes,cost-basis-in-pennies,quantity,date-entered,date-purchased,grading-company,grading-cert-id,folder
11816142,Beautifly #219,Pokemon Ascended Heroes,761,Ungraded,Normal wear,,,0,1,2026-04-05,2026-04-05,,,
11748981,Snorlax #62,Pokemon Japanese Nihil Zero,188,Ungraded,Normal wear,,,250,2,2026-03-04,2026-03-04,,,
10026877,Samurott [Reverse] #23,Pokemon White Flare,20,Ungraded,Normal wear,,,0,1,2026-04-04,2026-04-03,,,White flare
12117859,Lickitung #207,Pokemon Chinese Gem Pack 4,1121,Ungraded,Normal wear,,,0,1,2026-03-11,,,,
4830318,Psyduck,Pokemon Japanese Old Maid,1836,CGC 10,Normal wear,,,0,1,2026-03-19,2026-03-19,CGC,,
6142474,Ho-Oh ex #7,Pokemon TCG Classic: Charizard Deck,725,Graded 8,Normal wear,,,0,1,2026-04-11,2026-04-11,CGC,,
CSV;

test('it parses the row count, skipping the header', function () {
    expect((new ParsePricechartingCsv)(PC_CSV))->toHaveCount(6);
});

test('it splits name, number, and variant out of the product name', function () {
    $rows = (new ParsePricechartingCsv)(PC_CSV);

    expect($rows[0]->name)->toBe('Beautifly')
        ->and($rows[0]->number)->toBe('219')
        ->and($rows[0]->variant)->toBeNull();

    // "[Reverse]" tag becomes the variant; the number still resolves.
    expect($rows[2]->name)->toBe('Samurott')
        ->and($rows[2]->number)->toBe('23')
        ->and($rows[2]->variant)->toBe('reverse');
});

test('it extracts language and product line from the console-name', function () {
    $rows = (new ParsePricechartingCsv)(PC_CSV);

    expect($rows[0]->language)->toBe('en')
        ->and($rows[0]->setName)->toBe('Ascended Heroes')
        ->and($rows[0]->productLine)->toBe('pokemon');

    expect($rows[1]->language)->toBe('ja')
        ->and($rows[1]->setName)->toBe('Nihil Zero');

    expect($rows[3]->language)->toBe('zh')
        ->and($rows[3]->setName)->toBe('Gem Pack 4');
});

test('it reads raw vs graded state', function () {
    $rows = (new ParsePricechartingCsv)(PC_CSV);

    // Raw → default Near Mint (PriceCharting has no condition granularity).
    expect($rows[0]->condition)->toBe('NM')
        ->and($rows[0]->isGraded())->toBeFalse();

    // "CGC 10" → company + grade inline.
    expect($rows[4]->gradingCompany)->toBe('cgc')
        ->and($rows[4]->grade)->toBe(10.0)
        ->and($rows[4]->number)->toBeNull(); // no collector number in the name

    // "Graded 8" → grade from include-string, company from the column.
    expect($rows[5]->grade)->toBe(8.0)
        ->and($rows[5]->gradingCompany)->toBe('cgc');
});

test('it carries quantity, cost basis, folder, and acquired date', function () {
    $rows = (new ParsePricechartingCsv)(PC_CSV);

    expect($rows[1]->quantity)->toBe(2)
        ->and($rows[1]->costBasisCents)->toBe(250) // row total, not per-unit
        ->and($rows[2]->folder)->toBe('White flare')
        ->and($rows[0]->acquiredAt)->toBe('2026-04-05')
        ->and($rows[3]->acquiredAt)->toBeNull(); // empty date-purchased
});
