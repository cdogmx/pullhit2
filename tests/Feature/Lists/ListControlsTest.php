<?php

use App\Actions\Collection\AddToCollection;
use App\Actions\Wishlist\AddToWishlist;
use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\Set;
use App\Models\User;
use App\Support\Lists\ListControls;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->set = Set::factory()->create(['name' => 'Surging Sparks']);

    $this->card = function (string $name, string $rarity, ?int $median, string $number = '1') {
        // rarity is a generated column off attributes->rarity, so it is set
        // there and read from the indexed column.
        $item = CatalogItem::factory()->for($this->set)->create([
            'name' => $name, 'number' => $number,
            'attributes' => ['language' => 'en', 'rarity' => $rarity],
        ]);

        if ($median !== null) {
            MarketValue::factory()->for($item)->create([
                'state_key' => 'NM', 'condition' => Condition::NearMint, 'median' => $median,
            ]);
        }

        return $item;
    };
});

test('an unknown sort falls back to the default instead of failing', function () {
    // These values sit in URLs people edit, bookmark and paste. A typo should
    // give them the list they expected, not a 500.
    expect(ListControls::fromRequest(Request::create('/collection?sort=hottest'))->sort)
        ->toBe(ListControls::DEFAULT_SORT);
});

test('a single rarity in the query string works as well as a list', function () {
    expect(ListControls::fromRequest(Request::create('/collection?rarity=Common'))->rarities)
        ->toBe(['Common'])
        ->and(ListControls::fromRequest(Request::create('/collection?rarity[]=Common&rarity[]=Rare'))->rarities)
        ->toBe(['Common', 'Rare']);
});

test('blank and duplicate rarities are dropped', function () {
    $controls = ListControls::fromRequest(Request::create('/collection?rarity[]=Rare&rarity[]=&rarity[]=Rare'));

    expect($controls->rarities)->toBe(['Rare'])
        ->and($controls->isFiltered())->toBeTrue();
});

test('the collection filters by rarity and its totals follow the filter', function () {
    $add = app(AddToCollection::class);
    $add($this->user, ($this->card)('Pikachu', 'Illustration Rare', 5000), ['condition' => 'NM', 'quantity' => 1]);
    $add($this->user, ($this->card)('Bulbasaur', 'Common', 100), ['condition' => 'NM', 'quantity' => 1]);

    $this->actingAs($this->user)
        ->get('/collection?rarity%5B%5D=Illustration+Rare')
        ->assertInertia(fn ($page) => $page
            ->where('summary.item_count', 1)
            // The total is for what is shown. A collection-wide total above a
            // filtered list reads as an error in the numbers.
            ->where('summary.total_value', 5000)
            ->where('filters.rarity', ['Illustration Rare'])
        );
});

test('the wishlist filters by the same rarity the collection does', function () {
    $add = app(AddToWishlist::class);
    $add($this->user, ($this->card)('Pikachu', 'Illustration Rare', 5000), []);
    $add($this->user, ($this->card)('Bulbasaur', 'Common', 100), []);

    $this->actingAs($this->user)
        ->get('/wishlist?rarity%5B%5D=Illustration+Rare')
        ->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.catalog_item.name', 'Pikachu')
            ->where('filters.rarity', ['Illustration Rare'])
        );
});

test('the checkboxes list every rarity in the list, not just the filtered ones', function () {
    // The bug this prevents: derive the options from the filtered result and
    // they vanish as they are ticked, leaving no way to untick the last one.
    $add = app(AddToCollection::class);
    $add($this->user, ($this->card)('Pikachu', 'Illustration Rare', 5000), ['condition' => 'NM', 'quantity' => 1]);
    $add($this->user, ($this->card)('Bulbasaur', 'Common', 100), ['condition' => 'NM', 'quantity' => 1]);

    $this->actingAs($this->user)
        ->get('/collection?rarity%5B%5D=Illustration+Rare')
        ->assertInertia(fn ($page) => $page->has('rarityOptions', 2));
});

test('sorting by value puts a card we cannot price last, not first', function () {
    $add = app(AddToCollection::class);
    $add($this->user, ($this->card)('Cheap', 'Common', 100), ['condition' => 'NM', 'quantity' => 1]);
    $add($this->user, ($this->card)('Unpriced', 'Common', null), ['condition' => 'NM', 'quantity' => 1]);
    $add($this->user, ($this->card)('Pricey', 'Common', 90000), ['condition' => 'NM', 'quantity' => 1]);

    // Cheapest first must not mean "the ones we know nothing about first" —
    // an unvalued card is unknown, not free.
    $this->actingAs($this->user)
        ->get('/collection?sort=value_asc')
        ->assertInertia(fn ($page) => $page
            ->where('holdings.0.catalog_item.name', 'Cheap')
            ->where('holdings.1.catalog_item.name', 'Pricey')
            ->where('holdings.2.catalog_item.name', 'Unpriced')
        );
});

test('sorting by value counts the whole holding, not the unit price', function () {
    $add = app(AddToCollection::class);
    $add($this->user, ($this->card)('Ten of them', 'Common', 1000), ['condition' => 'NM', 'quantity' => 10]);
    $add($this->user, ($this->card)('Just one', 'Common', 5000), ['condition' => 'NM', 'quantity' => 1]);

    $this->actingAs($this->user)
        ->get('/collection?sort=value_desc')
        ->assertInertia(fn ($page) => $page->where('holdings.0.catalog_item.name', 'Ten of them'));
});

test('sorting inside a set is collector order, not alphabetical', function () {
    $add = app(AddToCollection::class);
    foreach (['9', '10', '2'] as $n) {
        $add($this->user, ($this->card)("Card {$n}", 'Common', 100, $n), ['condition' => 'NM', 'quantity' => 1]);
    }

    // "10" sorts after "9", not between "1" and "2".
    $this->actingAs($this->user)
        ->get('/collection?sort=set')
        ->assertInertia(fn ($page) => $page
            ->where('holdings.0.catalog_item.number', '2')
            ->where('holdings.1.catalog_item.number', '9')
            ->where('holdings.2.catalog_item.number', '10')
        );
});

test('filtering to nothing is an empty list, not an error', function () {
    app(AddToCollection::class)($this->user, ($this->card)('Pikachu', 'Common', 100), ['condition' => 'NM', 'quantity' => 1]);

    $this->actingAs($this->user)
        ->get('/collection?rarity%5B%5D=Nonexistent+Rarity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('holdings', 0)->where('summary.total_value', 0));
});

test('sorting by profit puts a holding with no cost basis last', function () {
    $add = app(AddToCollection::class);
    $add($this->user, ($this->card)('Winner', 'Common', 5000), ['condition' => 'NM', 'quantity' => 1, 'unit_cost' => 1000]);
    $add($this->user, ($this->card)('Loser', 'Common', 500), ['condition' => 'NM', 'quantity' => 1, 'unit_cost' => 4000]);

    $this->actingAs($this->user)
        ->get('/collection?sort=gain_desc')
        ->assertInertia(fn ($page) => $page
            ->where('holdings.0.catalog_item.name', 'Winner')
            ->where('holdings.1.catalog_item.name', 'Loser')
        );
});

test('the wishlist accepts the collection-only sorts without breaking', function () {
    // The bar is shared, so a sort carried over in a pasted URL must not 500 a
    // list that has no cost basis to sort by.
    app(AddToWishlist::class)($this->user, ($this->card)('Pikachu', 'Common', 100), []);

    $this->actingAs($this->user)->get('/wishlist?sort=gain_desc')->assertOk();
    $this->actingAs($this->user)->get('/wishlist?sort=quantity')->assertOk();
});

test('every sort the bar offers is one the server accepts', function () {
    // The React list and ListControls::SORTS are written separately; this is
    // what stops them drifting into a sort that silently falls back to default.
    $tsx = file_get_contents(resource_path('js/components/shared/list-controls.tsx'));
    preg_match_all("/\{ value: '([a-z_]+)', label:/", $tsx, $m);

    expect($m[1])->not->toBeEmpty()
        ->and(array_diff($m[1], ListControls::SORTS))->toBe([]);
});

test('search matches the card name, its number, or its set', function () {
    $add = app(AddToCollection::class);
    $add($this->user, ($this->card)('Pikachu ex', 'Common', 100, '238'), ['condition' => 'NM', 'quantity' => 1]);
    $add($this->user, ($this->card)('Snorlax', 'Common', 100, '145'), ['condition' => 'NM', 'quantity' => 1]);

    $find = fn (string $term) => $this->actingAs($this->user)
        ->get('/collection?q='.urlencode($term));

    $find('pikachu')->assertInertia(fn ($p) => $p->has('holdings', 1)
        ->where('holdings.0.catalog_item.name', 'Pikachu ex'));
    $find('145')->assertInertia(fn ($p) => $p->has('holdings', 1)
        ->where('holdings.0.catalog_item.name', 'Snorlax'));
    // The set both cards share.
    $find('Surging')->assertInertia(fn ($p) => $p->has('holdings', 2));
});

test('a wildcard typed into search is a literal, not a pattern', function () {
    // "%" unescaped turns a narrowing search into one that matches everything —
    // the search would quietly stop working exactly when someone typed a symbol.
    app(AddToCollection::class)($this->user, ($this->card)('Pikachu', 'Common', 100), ['condition' => 'NM', 'quantity' => 1]);

    $this->actingAs($this->user)
        ->get('/collection?q=%25')
        ->assertInertia(fn ($p) => $p->has('holdings', 0));
});

test('search narrows the wishlist the same way', function () {
    $add = app(AddToWishlist::class);
    $add($this->user, ($this->card)('Pikachu ex', 'Common', 100), []);
    $add($this->user, ($this->card)('Snorlax', 'Common', 100), []);

    $this->actingAs($this->user)
        ->get('/wishlist?q=snorlax')
        ->assertInertia(fn ($p) => $p->has('items', 1)
            ->where('items.0.catalog_item.name', 'Snorlax'));
});

test('the set list comes from the whole collection, not the filtered one', function () {
    // Same trap as the rarity boxes: derive it from the filtered rows and the
    // dropdown ends up holding only the set already chosen.
    $other = Set::factory()->create(['name' => 'Prismatic Evolutions']);
    $add = app(AddToCollection::class);
    $add($this->user, ($this->card)('Pikachu', 'Common', 100), ['condition' => 'NM', 'quantity' => 1]);

    $second = CatalogItem::factory()->for($other)->create([
        'name' => 'Eevee', 'number' => '5', 'attributes' => ['language' => 'en', 'rarity' => 'Common'],
    ]);
    $add($this->user, $second, ['condition' => 'NM', 'quantity' => 1]);

    $this->actingAs($this->user)
        ->get('/collection?set=Surging+Sparks')
        ->assertInertia(fn ($p) => $p->has('holdings', 1)->has('setOptions', 2));
});

test('a wishlist URL carrying a collection-only filter does not break it', function () {
    // The bar is shared, so ?folder= and ?for_sale=1 can be pasted onto a list
    // whose table has neither column.
    app(AddToWishlist::class)($this->user, ($this->card)('Pikachu', 'Common', 100), []);

    $this->actingAs($this->user)->get('/wishlist?folder=Binder&for_sale=1')->assertOk();
});

test('filters compose rather than replace one another', function () {
    $add = app(AddToCollection::class);
    $add($this->user, ($this->card)('Pikachu ex', 'Illustration Rare', 100), ['condition' => 'NM', 'quantity' => 1]);
    $add($this->user, ($this->card)('Pikachu', 'Common', 100), ['condition' => 'NM', 'quantity' => 1]);
    $add($this->user, ($this->card)('Snorlax', 'Illustration Rare', 100), ['condition' => 'NM', 'quantity' => 1]);

    $this->actingAs($this->user)
        ->get('/collection?q=pikachu&rarity%5B%5D=Illustration+Rare')
        ->assertInertia(fn ($p) => $p->has('holdings', 1)
            ->where('holdings.0.catalog_item.name', 'Pikachu ex'));
});

test('a public collection page filters by search and rarity', function () {
    $this->user->forceFill(['username' => 'CardFoo'])->save();
    $collection = $this->user->collections()->create([
        'name' => 'For sale', 'slug' => 'for-sale', 'is_public' => true, 'is_default' => false,
    ]);

    $add = app(AddToCollection::class);
    $add($this->user, ($this->card)('Pikachu ex', 'Illustration Rare', 5000),
        ['condition' => 'NM', 'quantity' => 1, 'collection_id' => $collection->id]);
    $add($this->user, ($this->card)('Bulbasaur', 'Common', 100),
        ['condition' => 'NM', 'quantity' => 1, 'collection_id' => $collection->id]);

    $this->get('/collection/CardFoo/for-sale?rarity%5B%5D=Illustration+Rare')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('holdings', 1)
            ->where('holdings.0.name', 'Pikachu ex')
            // The headline follows the filter: a share link says "here is the
            // part worth looking at", and a whole-collection total above two
            // cards reads as their worth.
            ->where('summary.total_value', 5000)
            ->has('rarityOptions', 2)
        );

    $this->get('/collection/CardFoo/for-sale?q=bulba')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('holdings', 1)
            ->where('holdings.0.name', 'Bulbasaur'));
});

test('a filter cannot prise open a collection that is not public', function () {
    // The filter runs inside the page, so it must never become a way to read a
    // list the owner has not shared.
    $this->user->forceFill(['username' => 'Private'])->save();
    $collection = $this->user->collections()->create([
        'name' => 'Hidden', 'slug' => 'hidden', 'is_public' => false, 'is_default' => false,
    ]);
    app(AddToCollection::class)($this->user, ($this->card)('Pikachu', 'Common', 100),
        ['condition' => 'NM', 'quantity' => 1, 'collection_id' => $collection->id]);

    $this->get('/collection/Private/hidden?q=pikachu')->assertNotFound();
});
