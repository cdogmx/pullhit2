<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->create([
        'vertical_id' => $this->vertical->id,
        'slug' => 'one-piece',
        'name' => 'One Piece Card Game',
    ]);
});

function healthSet(string $name, array $extra = []): Set
{
    return Set::factory()->create(array_merge([
        'product_line_id' => test()->line->id,
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::random(4),
        'language' => 'en',
    ], $extra));
}

function healthCard(Set $set, string $number, array $attributes = [], ?string $image = null)
{
    $item = app(CreateCatalogItem::class)(
        vertical: test()->vertical,
        productLine: test()->line,
        set: $set,
        itemType: ItemType::Single,
        name: 'Card '.$number,
        number: $number,
        attributes: array_merge(['language' => 'en', 'variant' => 'normal'], $attributes),
    );

    if ($image) {
        $item->forceFill(['primary_image_path' => $image])->save();
    }

    return $item;
}

/** Pull one set's row out of the Inertia payload. */
function healthRow(array $rows, string $name): array
{
    return collect($rows)->firstWhere('name', $name) ?? [];
}

test('it reports what share of a set carries each fact', function () {
    $set = healthSet('Half Described');
    // Two of four have a rarity; one of four has a type; two have an image.
    healthCard($set, 'OP01-001', ['rarity' => 'R', 'type' => 'Red'], 'a.png');
    healthCard($set, 'OP01-002', ['rarity' => 'C'], 'b.png');
    healthCard($set, 'OP01-003');
    healthCard($set, 'OP01-004');

    $this->actingAs($this->admin)
        ->get('/admin/set-health')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/set-health')
            ->where('rows.0.items', 4)
            ->where('rows.0.coverage.rarity', 50)
            ->where('rows.0.coverage.type', 25)
            ->where('rows.0.coverage.image', 50)
            ->where('rows.0.coverage.number', 100));
});

test('an empty set reads as complete rather than as zero', function () {
    // Nothing to describe is not the same failure as everything undescribed;
    // scoring it 0 would bury the real gaps under sets that hold no cards.
    healthSet('Announced But Not Imported');

    $this->actingAs($this->admin)
        ->get('/admin/set-health')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.items', 0)
            ->where('rows.0.health', 100));
});

test('the value column counts cards with a market value, not values', function () {
    $set = healthSet('Valued');
    $card = healthCard($set, 'OP01-001');
    healthCard($set, 'OP01-002');

    // Two values on one card must not read as two cards covered.
    MarketValue::factory()->create(['catalog_item_id' => $card->id, 'state_key' => 'NM']);
    MarketValue::factory()->create(['catalog_item_id' => $card->id, 'state_key' => 'PSA10']);

    $this->actingAs($this->admin)
        ->get('/admin/set-health')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.coverage.value', 50));
});

test('sealed products are not judged on rarity, type or number', function () {
    $set = healthSet('Booster Box Only');
    app(CreateCatalogItem::class)(
        vertical: $this->vertical,
        productLine: $this->line,
        set: $set,
        itemType: ItemType::Sealed,
        name: 'Booster Box',
        number: null,
        attributes: ['language' => 'en', 'sealed_type' => 'booster_box'],
    );

    $this->actingAs($this->admin)
        ->get('/admin/set-health')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.items', 1)
            ->where('rows.0.singles', 0)
            // No singles to describe — the card facets do not drag it down.
            ->where('rows.0.coverage.rarity', 100)
            ->where('rows.0.coverage.type', 100));
});

test('worst-described sets come first by default', function () {
    $good = healthSet('Fully Described');
    healthCard($good, 'OP01-001', ['rarity' => 'R', 'type' => 'Red'], 'a.png');
    MarketValue::factory()->create([
        'catalog_item_id' => $good->catalogItems()->first()->id,
    ]);

    $bad = healthSet('Bare');
    healthCard($bad, 'OP02-001');

    $this->actingAs($this->admin)
        ->get('/admin/set-health')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.name', 'Bare')
            ->where('rows.1.name', 'Fully Described'));
});

test('the problems filter hides sets that are fully described', function () {
    $good = healthSet('Fully Described');
    $card = healthCard($good, 'OP01-001', ['rarity' => 'R', 'type' => 'Red'], 'a.png');
    MarketValue::factory()->create(['catalog_item_id' => $card->id]);

    $bad = healthSet('Bare');
    healthCard($bad, 'OP02-001');

    $this->actingAs($this->admin)
        ->get('/admin/set-health?only=problems')
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.name', 'Bare'));
});

test('the unlinked filter finds sets with no upstream group', function () {
    healthSet('Linked', ['external_ids' => ['tcgplayer_group_id' => '23387']]);
    healthSet('Adrift');

    $this->actingAs($this->admin)
        ->get('/admin/set-health?only=unlinked')
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.name', 'Adrift'));
});

test('search matches a set by name, code or series', function () {
    healthSet('Kingdoms of Intrigue', ['code' => 'OP04', 'series' => 'Original']);
    healthSet('Paramount War', ['code' => 'OP02', 'series' => 'Original']);

    $this->actingAs($this->admin)
        ->get('/admin/set-health?q=OP04')
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.name', 'Kingdoms of Intrigue'));
});

test('a LIKE wildcard in the term is searched for literally, not honoured', function () {
    // Someone searching "OP04%" means the characters, not "anything after OP04".
    // Left in the pattern a stray % matches every row and scans the table.
    healthSet('Kingdoms of Intrigue', ['code' => 'OP04']);
    healthSet('Paramount War', ['code' => 'OP02']);

    $this->actingAs($this->admin)
        ->get('/admin/set-health?q='.urlencode('OP04%'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.name', 'Kingdoms of Intrigue'));
});

test('an unknown sort key falls back rather than reaching SQL', function () {
    healthSet('Anything');

    $this->actingAs($this->admin)
        ->get('/admin/set-health?sort='.urlencode('items; DROP TABLE sets'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.sort', 'health'));

    expect(Set::count())->toBe(1);
});

test('linking a set records the group id without disturbing its other ids', function () {
    $set = healthSet('Promo', ['external_ids' => ['ptcgio_id' => 'svp']]);

    $this->actingAs($this->admin)
        ->post("/admin/set-health/{$set->id}/link", ['group_id' => '17675'])
        ->assertRedirect();

    expect($set->fresh()->external_ids)
        ->toBe(['ptcgio_id' => 'svp', 'tcgplayer_group_id' => '17675']);
});

test('clearing a link removes only the group id', function () {
    $set = healthSet('Promo', ['external_ids' => [
        'ptcgio_id' => 'svp', 'tcgplayer_group_id' => '17675',
    ]]);

    $this->actingAs($this->admin)
        ->post("/admin/set-health/{$set->id}/link", ['group_id' => null])
        ->assertRedirect();

    expect($set->fresh()->external_ids)->toBe(['ptcgio_id' => 'svp']);
});

test('candidates are ranked by similarity and never applied automatically', function () {
    Http::fake(['tcgcsv.com/tcgplayer/68/groups' => Http::response(['results' => [
        ['groupId' => 24284, 'name' => 'Starter Deck 11: Uta', 'abbreviation' => 'ST-11'],
        ['groupId' => 24285, 'name' => 'Starter Deck 16: Uta', 'abbreviation' => 'ST-16'],
        ['groupId' => 17675, 'name' => 'Wings of the Captain', 'abbreviation' => 'OP06'],
    ]])]);

    $set = healthSet('Starter Deck 16: Uta');

    $response = $this->actingAs($this->admin)
        ->getJson("/admin/set-health/{$set->id}/candidates")
        ->assertOk();

    $candidates = $response->json('candidates');

    // The exact name leads, but the sibling deck scores within a few points of
    // it — which is precisely why a person picks and the importer does not.
    expect($candidates[0]['name'])->toBe('Starter Deck 16: Uta')
        ->and($candidates[1]['name'])->toBe('Starter Deck 11: Uta')
        ->and($candidates[1]['score'])->toBeGreaterThan(90)
        // Nothing was written just by looking.
        ->and($set->fresh()->external_ids['tcgplayer_group_id'] ?? null)->toBeNull();
});

test('candidates say so plainly when the brand has no upstream feed', function () {
    $riftbound = ProductLine::factory()->create([
        'vertical_id' => $this->vertical->id, 'slug' => 'riftbound', 'name' => 'Riftbound',
    ]);
    $set = Set::factory()->create([
        'product_line_id' => $riftbound->id, 'name' => 'Origins', 'slug' => 'origins-rb',
    ]);

    $this->actingAs($this->admin)
        ->getJson("/admin/set-health/{$set->id}/candidates")
        ->assertOk()
        ->assertJson(['candidates' => []])
        ->assertJsonPath('reason', 'This brand is not carried by TCGCSV.');
});

test('backfilling one set fills its cards and leaves other sets alone', function () {
    Http::fake([
        'tcgcsv.com/tcgplayer/68/groups' => Http::response(['results' => [
            ['groupId' => 23387, 'name' => 'Target Set', 'abbreviation' => 'OP07'],
            ['groupId' => 99999, 'name' => 'Other Set', 'abbreviation' => 'OP08'],
        ]]),
        'tcgcsv.com/tcgplayer/68/23387/products' => Http::response(['results' => [[
            'productId' => 1, 'name' => 'Card OP07-001',
            'extendedData' => [
                ['name' => 'Number', 'value' => 'OP07-001'],
                ['name' => 'Rarity', 'value' => 'SR'],
                ['name' => 'Color', 'value' => 'Green'],
            ],
        ]]]),
        'tcgcsv.com/tcgplayer/68/99999/products' => Http::response(['results' => [[
            'productId' => 2, 'name' => 'Card OP08-001',
            'extendedData' => [
                ['name' => 'Number', 'value' => 'OP08-001'],
                ['name' => 'Rarity', 'value' => 'L'],
                ['name' => 'Color', 'value' => 'Blue'],
            ],
        ]]]),
    ]);

    $target = healthSet('Target Set', ['code' => 'OP07']);
    $mine = healthCard($target, 'OP07-001');

    $other = healthSet('Other Set', ['code' => 'OP08']);
    $theirs = healthCard($other, 'OP08-001');

    $this->actingAs($this->admin)
        ->post("/admin/set-health/{$target->id}/backfill")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($mine->fresh()->getAttribute('attributes'))
        ->toMatchArray(['rarity' => 'SR', 'type' => 'Green'])
        // Backfilling one set must not sweep the whole product line.
        ->and($theirs->fresh()->getAttribute('attributes'))->not->toHaveKey('rarity');
});

test('backfilling refuses a brand with no upstream feed instead of erroring', function () {
    $riftbound = ProductLine::factory()->create([
        'vertical_id' => $this->vertical->id, 'slug' => 'riftbound', 'name' => 'Riftbound',
    ]);
    $set = Set::factory()->create([
        'product_line_id' => $riftbound->id, 'name' => 'Origins', 'slug' => 'origins-rb2',
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/set-health/{$set->id}/backfill")
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('a signed-in non-admin is refused', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get('/admin/set-health')
        ->assertForbidden();
});

test('a guest is sent to sign in', function () {
    $this->get('/admin/set-health')->assertRedirect('/login');
});

test('a non-admin cannot link or backfill a set', function () {
    $set = healthSet('Guarded');

    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->post("/admin/set-health/{$set->id}/link", ['group_id' => '1'])->assertForbidden();
    $this->post("/admin/set-health/{$set->id}/backfill")->assertForbidden();

    expect($set->fresh()->external_ids['tcgplayer_group_id'] ?? null)->toBeNull();
});
