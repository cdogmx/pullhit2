<?php

use App\Actions\Scanning\ScanCards;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * A scan of a card we do not hold, end to end.
 *
 * The two negative cases here are the ones that matter. A read that ALMOST
 * matched something is far more likely to be a card we already hold under a
 * slightly different name than a genuinely new card, and a hesitant read makes a
 * row with a wrong name — which the identity hash is built from, so the official
 * import will never match it and the duplicate is permanent.
 */
beforeEach(function () {
    Storage::fake('s3');
    config([
        'services.anthropic.key' => 'test-key',
        'scanning.provisional.enabled' => true,
    ]);

    $this->vertical = Vertical::firstOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
    $this->user = User::factory()->create();
});

/** The vision identify tool's response for a single card. */
function fakeIdentify(array $fields): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'tool_use',
                'name' => 'record_card',
                'input' => $fields,
            ]],
        ], 200),
    ]);
}

/**
 * $seed varies the image bytes, and so the perceptual hash.
 *
 * It matters: the fingerprint cache recognises a repeat of the SAME image as the
 * same card and short-circuits the whole read. Reusing one image across scans of
 * different cards makes every scan after the first a cache hit — which is correct
 * behaviour and a broken test.
 */
function scan(User $user, string $seed = 'a'): array
{
    return app(ScanCards::class)($user, base64_encode('not-a-real-image-'.$seed), 'image/jpeg', 'single');
}

test('an unmatched card from a brand we do not hold is created and offered', function () {
    fakeIdentify([
        'name' => 'Ahsoka Tano', 'number' => '042', 'set_name' => 'Shadows of the Galaxy',
        'game' => 'Star Wars Unlimited', 'language' => 'en', 'is_graded' => false, 'confidence' => 0.93,
    ]);

    $result = scan($this->user);
    $card = $result['detected'][0];

    expect($card['added_provisionally'])->toBeTrue()
        // Offered as the top candidate, so confirming behaves exactly as it does
        // for a card we already held.
        ->and($card['candidates'][0]['reasons'])->toContain('added from your scan');

    $created = CatalogItem::where('name', 'Ahsoka Tano')->first();

    expect($created)->not->toBeNull()
        ->and($created->is_provisional)->toBeTrue()
        ->and($created->provisional_by)->toBe($this->user->id)
        ->and(ProductLine::where('slug', 'star-wars-unlimited')->exists())->toBeTrue();
});

test('a read that nearly matches a card we hold creates nothing', function () {
    // The expensive mistake: a near-duplicate of a card already in the catalog.
    $line = ProductLine::factory()->create(['vertical_id' => $this->vertical->id, 'slug' => 'pokemon']);
    $set = Set::factory()->create(['product_line_id' => $line->id, 'name' => 'Base', 'slug' => 'base']);
    CatalogItem::factory()->create([
        'vertical_id' => $this->vertical->id, 'product_line_id' => $line->id, 'set_id' => $set->id,
        'name' => 'Pikachu', 'number' => '58',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);

    fakeIdentify([
        'name' => 'Pikachu', 'number' => '58', 'set_name' => 'Base', 'game' => 'Pokemon',
        'language' => 'en', 'is_graded' => false, 'confidence' => 0.95,
    ]);

    $result = scan($this->user);

    expect($result['detected'][0]['added_provisionally'])->toBeFalse()
        ->and(CatalogItem::where('is_provisional', true)->count())->toBe(0);
});

test('a hesitant read creates nothing, however unmatched it is', function () {
    fakeIdentify([
        'name' => 'Something Blurry', 'number' => null, 'set_name' => null, 'game' => null,
        'language' => 'en', 'is_graded' => false, 'confidence' => 0.3,
    ]);

    $result = scan($this->user);

    expect($result['detected'][0]['added_provisionally'])->toBeFalse()
        ->and(CatalogItem::where('is_provisional', true)->count())->toBe(0);
});

test('one person cannot create more rows than the daily cap', function () {
    // A scanner pointed at something that is not a card should cost a handful of
    // rows, not a catalog.
    //
    // The cap is tested against rows already on the clock rather than by scanning
    // repeatedly: identical fake image bytes hash identically, so a second scan
    // is answered from the fingerprint cache and never reaches this decision.
    config(['scanning.provisional.daily_per_user' => 2]);

    CatalogItem::factory()->count(2)->create([
        'is_provisional' => true,
        'provisional_by' => $this->user->id,
        'provisional_at' => now()->subHour(),
    ]);

    fakeIdentify([
        'name' => 'One Too Many', 'number' => '999', 'set_name' => 'Some Set', 'game' => 'Some Game',
        'language' => 'en', 'is_graded' => false, 'confidence' => 0.9,
    ]);

    $result = scan($this->user);

    expect($result['detected'][0]['added_provisionally'])->toBeFalse()
        ->and(CatalogItem::where('name', 'One Too Many')->exists())->toBeFalse();
});

test("yesterday's rows do not count against today's cap", function () {
    config(['scanning.provisional.daily_per_user' => 2]);

    CatalogItem::factory()->count(2)->create([
        'is_provisional' => true,
        'provisional_by' => $this->user->id,
        'provisional_at' => now()->subDays(3),
    ]);

    fakeIdentify([
        'name' => 'Fresh Day', 'number' => '998', 'set_name' => 'Some Set', 'game' => 'Some Game',
        'language' => 'en', 'is_graded' => false, 'confidence' => 0.9,
    ]);

    scan($this->user);

    expect(CatalogItem::where('name', 'Fresh Day')->exists())->toBeTrue();
});

test('the feature can be switched off entirely', function () {
    config(['scanning.provisional.enabled' => false]);

    fakeIdentify([
        'name' => 'Ahsoka Tano', 'number' => '042', 'set_name' => 'Shadows of the Galaxy',
        'game' => 'Star Wars Unlimited', 'language' => 'en', 'is_graded' => false, 'confidence' => 0.93,
    ]);

    $result = scan($this->user);

    expect($result['detected'][0]['added_provisionally'])->toBeFalse()
        ->and(CatalogItem::count())->toBe(0);
});
