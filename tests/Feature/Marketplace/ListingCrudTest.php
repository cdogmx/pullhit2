<?php

use App\Enums\ListingCategory;
use App\Enums\ListingStatus;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingPhoto;
use App\Models\User;
use App\Support\Marketplace\ListingPhotoStore;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seller = User::factory()->create(['username' => 'seller']);

    // The graders are reference data the app seeds; tests start empty.
    foreach ([['psa', 'PSA'], ['bgs', 'Beckett (BGS)'], ['cgc', 'CGC']] as [$slug, $name]) {
        GradingCompany::firstOrCreate(['slug' => $slug], ['name' => $name]);
    }

    // The real store re-encodes through GD and writes to S3; neither belongs in
    // a CRUD test. What it does to EXIF is tested on its own.
    $this->app->bind(ListingPhotoStore::class, fn () => new class extends ListingPhotoStore
    {
        public function store(UploadedFile $file): ?string
        {
            return 'https://example.test/'.$file->hashName().'.jpg';
        }
    });
});

/** The minimum a form must send. */
function listingPayload(array $overrides = []): array
{
    return array_merge([
        'category' => ListingCategory::RawSingle->value,
        'title' => 'Charizard VMAX Alt Art',
        'description' => 'Pack fresh.',
        'price_cents' => 125000,
        'condition' => 'NM',
        'accepts_offers' => true,
        'accepts_direct' => true,
        'accepts_escrow' => true,
        'publish' => true,
    ], $overrides);
}

test('a seller can publish a listing with a photo', function () {
    $this->actingAs($this->seller)
        ->post('/marketplace', listingPayload([
            'photos' => [UploadedFile::fake()->image('front.jpg')],
        ]))
        ->assertRedirect();

    $listing = MarketplaceListing::firstOrFail();

    expect($listing->status)->toBe(ListingStatus::Active)
        ->and($listing->user_id)->toBe($this->seller->id)
        ->and($listing->price_cents)->toBe(125000)
        ->and($listing->photos)->toHaveCount(1)
        // A published listing gets a life; the browse query drops it when it ends.
        ->and($listing->expires_at)->not->toBeNull();
});

test('publishing without a photo is refused and saved as a draft', function () {
    // A listing with no photo is the strongest scam signal a marketplace has,
    // so this is gated rather than trusted to the form — but the seller keeps
    // their work.
    $this->actingAs($this->seller)
        ->post('/marketplace', listingPayload())
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(MarketplaceListing::firstOrFail()->status)->toBe(ListingStatus::Draft);
});

test('a slab keeps its grading facets and drops the raw condition', function () {
    $psa = GradingCompany::where('slug', 'psa')->firstOrFail();

    $this->actingAs($this->seller)->post('/marketplace', listingPayload([
        'category' => ListingCategory::GradedSlab->value,
        'grading_company_id' => $psa->id,
        'grade' => '10',
        'cert_number' => '12345678',
        'condition' => 'NM',
        'photos' => [UploadedFile::fake()->image('slab.jpg')],
    ]))->assertRedirect();

    $listing = MarketplaceListing::firstOrFail();

    expect($listing->grading_company_id)->toBe($psa->id)
        ->and($listing->cert_number)->toBe('12345678')
        // A slab's grade IS its condition; carrying both invites them to disagree,
        // and an ungraded "NM" on a PSA 10 would match the wrong comps.
        ->and($listing->condition)->toBeNull();
});

test('switching a slab to a raw single clears the grade it no longer has', function () {
    // The failure this prevents: a seller fills in a grade, changes their mind
    // about the category, and ships a PSA 10 attached to an ungraded card —
    // which then prices against graded comps at several times its worth.
    $psa = GradingCompany::where('slug', 'psa')->firstOrFail();

    $listing = MarketplaceListing::factory()->slab()->create([
        'user_id' => $this->seller->id,
        'grading_company_id' => $psa->id,
    ]);
    MarketplaceListingPhoto::factory()->create(['marketplace_listing_id' => $listing->id]);

    $this->actingAs($this->seller)->post("/marketplace/{$listing->id}", listingPayload([
        'category' => ListingCategory::RawSingle->value,
        'grading_company_id' => $psa->id,
        'grade' => '10',
        'cert_number' => '12345678',
        'condition' => 'LP',
    ]))->assertRedirect();

    $listing->refresh();

    expect($listing->category)->toBe(ListingCategory::RawSingle)
        ->and($listing->grading_company_id)->toBeNull()
        ->and($listing->grade)->toBeNull()
        ->and($listing->cert_number)->toBeNull()
        ->and($listing->condition->value)->toBe('LP');
});

test('linking a catalogued card copies its set and number across', function () {
    $card = CatalogItem::factory()->create(['name' => 'Charizard ex', 'number' => '223']);

    $this->actingAs($this->seller)->post('/marketplace', listingPayload([
        'catalog_item_id' => $card->id,
        'photos' => [UploadedFile::fake()->image('a.jpg')],
    ]))->assertRedirect();

    $listing = MarketplaceListing::firstOrFail();

    expect($listing->catalog_item_id)->toBe($card->id)
        // Denormalised so an unlinked listing is still searchable by them.
        ->and($listing->card_number)->toBe('223');
});

test('a listing that accepts neither payment path still accepts direct', function () {
    // Otherwise it is a listing nobody can act on.
    $this->actingAs($this->seller)->post('/marketplace', listingPayload([
        'accepts_direct' => false,
        'accepts_escrow' => false,
        'photos' => [UploadedFile::fake()->image('a.jpg')],
    ]))->assertRedirect();

    expect(MarketplaceListing::firstOrFail()->accepts_direct)->toBeTrue();
});

test('only the seller can edit their listing', function () {
    $listing = MarketplaceListing::factory()->create(['user_id' => $this->seller->id]);
    $other = User::factory()->create();

    $this->actingAs($other)->get("/marketplace/{$listing->id}/edit")->assertForbidden();
    $this->actingAs($other)->post("/marketplace/{$listing->id}", listingPayload())->assertForbidden();
    $this->actingAs($other)->delete("/marketplace/{$listing->id}")->assertForbidden();
});

test('removing a listing hides it without destroying it', function () {
    // Threads and deals point at listings; a sold card's history has to survive
    // the seller tidying up.
    $listing = MarketplaceListing::factory()->create(['user_id' => $this->seller->id]);

    $this->actingAs($this->seller)->delete("/marketplace/{$listing->id}")->assertRedirect();

    expect(MarketplaceListing::find($listing->id))->not->toBeNull()
        ->and($listing->fresh()->status)->toBe(ListingStatus::Removed);
});

test('an edit that never mentions photos does not delete them', function () {
    $listing = MarketplaceListing::factory()->create(['user_id' => $this->seller->id]);
    MarketplaceListingPhoto::factory()->count(2)->create(['marketplace_listing_id' => $listing->id]);

    $this->actingAs($this->seller)
        ->post("/marketplace/{$listing->id}", listingPayload(['title' => 'New title']))
        ->assertRedirect();

    expect($listing->fresh()->title)->toBe('New title')
        ->and($listing->fresh()->photos)->toHaveCount(2);
});

test('photos can be reordered and removed by what the form keeps', function () {
    $listing = MarketplaceListing::factory()->create(['user_id' => $this->seller->id]);
    $photos = MarketplaceListingPhoto::factory()->count(3)->sequence(
        ['sort_order' => 0], ['sort_order' => 1], ['sort_order' => 2],
    )->create(['marketplace_listing_id' => $listing->id]);

    // Keep the third and first, in that order; drop the second.
    $this->actingAs($this->seller)->post("/marketplace/{$listing->id}", listingPayload([
        'keep_photo_ids' => [$photos[2]->id, $photos[0]->id],
    ]))->assertRedirect();

    $kept = $listing->fresh()->photos;

    expect($kept)->toHaveCount(2)
        ->and($kept->pluck('id')->all())->toBe([$photos[2]->id, $photos[0]->id]);
});

test('a guest can browse but not list', function () {
    MarketplaceListing::factory()->count(2)->create(['user_id' => $this->seller->id]);

    $this->get('/marketplace')->assertOk();
    $this->get('/marketplace/new')->assertRedirect('/login');
    $this->post('/marketplace', listingPayload())->assertRedirect('/login');
});

test('browse shows only live listings from unbanned sellers', function () {
    $visible = MarketplaceListing::factory()->create(['user_id' => $this->seller->id, 'title' => 'Visible']);
    MarketplaceListing::factory()->draft()->create(['user_id' => $this->seller->id, 'title' => 'A draft']);
    MarketplaceListing::factory()->create([
        'user_id' => $this->seller->id, 'title' => 'Expired', 'expires_at' => now()->subDay(),
    ]);

    $banned = User::factory()->create(['banned_at' => now()]);
    MarketplaceListing::factory()->create(['user_id' => $banned->id, 'title' => 'From a banned seller']);

    $this->get('/marketplace')->assertInertia(fn (Assert $page) => $page
        ->component('marketplace/index')
        ->has('listings', 1)
        ->where('listings.0.id', $visible->id));
});

test('browse filters by category, price and search', function () {
    MarketplaceListing::factory()->create(['user_id' => $this->seller->id, 'title' => 'Cheap Pikachu', 'price_cents' => 500]);
    MarketplaceListing::factory()->slab()->create(['user_id' => $this->seller->id, 'title' => 'Slabbed Charizard', 'price_cents' => 500000]);

    $this->get('/marketplace?category=graded_slab')
        ->assertInertia(fn (Assert $page) => $page->has('listings', 1)->where('listings.0.title', 'Slabbed Charizard'));

    $this->get('/marketplace?max_price=1000')
        ->assertInertia(fn (Assert $page) => $page->has('listings', 1)->where('listings.0.title', 'Cheap Pikachu'));

    $this->get('/marketplace?q=pikachu')
        ->assertInertia(fn (Assert $page) => $page->has('listings', 1)->where('listings.0.title', 'Cheap Pikachu'));
});

test('the same cert offered twice is surfaced on the listing', function () {
    // Two people cannot both hold one slab. This is the loudest scam signal the
    // marketplace can produce, so a buyer sees it rather than it sitting in a
    // report queue.
    $mine = MarketplaceListing::factory()->slab('55555555')->create(['user_id' => $this->seller->id]);
    $theirs = MarketplaceListing::factory()->slab('55555555')->create(['user_id' => User::factory()]);

    $this->get("/marketplace/{$mine->id}")->assertInertia(fn (Assert $page) => $page
        ->component('marketplace/show')
        ->has('certConflicts', 1)
        ->where('certConflicts.0.id', $theirs->id));
});

test('a listing with a unique cert reports no conflict', function () {
    $listing = MarketplaceListing::factory()->slab('11112222')->create(['user_id' => $this->seller->id]);

    $this->get("/marketplace/{$listing->id}")
        ->assertInertia(fn (Assert $page) => $page->has('certConflicts', 0));
});

test('a price of zero is refused', function () {
    $this->actingAs($this->seller)
        ->post('/marketplace', listingPayload(['price_cents' => 0]))
        ->assertSessionHasErrors('price_cents');
});

test('a seller sees their own listings, drafts included', function () {
    // A draft is invisible everywhere else; without this page a seller who
    // saved one cannot find it again.
    MarketplaceListing::factory()->create(['user_id' => $this->seller->id, 'title' => 'Live one']);
    MarketplaceListing::factory()->draft()->create(['user_id' => $this->seller->id, 'title' => 'Unfinished']);
    MarketplaceListing::factory()->create(['user_id' => User::factory(), 'title' => 'Somebody else\'s']);

    $this->actingAs($this->seller)->get('/selling')
        ->assertInertia(fn (Assert $page) => $page
            ->component('marketplace/mine')
            ->has('listings', 2)
            ->where('counts.active', 1)
            ->where('counts.draft', 1));
});

test('drafts sort above everything else on the selling page', function () {
    // The thing needing attention goes first.
    MarketplaceListing::factory()->create(['user_id' => $this->seller->id, 'title' => 'Live']);
    MarketplaceListing::factory()->draft()->create(['user_id' => $this->seller->id, 'title' => 'Draft']);

    $this->actingAs($this->seller)->get('/selling')
        ->assertInertia(fn (Assert $page) => $page->where('listings.0.title', 'Draft'));
});

test('the selling page needs an account', function () {
    $this->get('/selling')->assertRedirect('/login');
});
