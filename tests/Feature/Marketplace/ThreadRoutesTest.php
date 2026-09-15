<?php

use App\Enums\DealStatus;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceThread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seller = User::factory()->create(['username' => 'seller']);
    $this->buyer = User::factory()->create(['username' => 'buyer']);
    $this->listing = MarketplaceListing::factory()->create(['user_id' => $this->seller->id]);
});

/** A live conversation between the two. */
function thread(): MarketplaceThread
{
    return MarketplaceThread::factory()->create([
        'marketplace_listing_id' => test()->listing->id,
        'buyer_id' => test()->buyer->id,
        'seller_id' => test()->seller->id,
    ]);
}

test('contacting a seller opens a thread and lands the buyer in it', function () {
    $this->actingAs($this->buyer)
        ->post("/marketplace/{$this->listing->id}/contact")
        ->assertRedirect();

    $thread = MarketplaceThread::firstOrFail();

    expect($thread->buyer_id)->toBe($this->buyer->id)
        ->and($thread->seller_id)->toBe($this->seller->id);
});

test('a seller contacting their own listing is told no', function () {
    $this->actingAs($this->seller)
        ->post("/marketplace/{$this->listing->id}/contact")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(MarketplaceThread::count())->toBe(0);
});

test('nobody outside the thread can read it', function () {
    // People arrange payment in here, in prose. The room has to be private or
    // the venue posture is a fiction.
    $thread = thread();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get("/messages/{$thread->id}")->assertForbidden();
    $this->actingAs($stranger)->post("/messages/{$thread->id}", ['body' => 'hi'])->assertForbidden();
    $this->actingAs($stranger)->getJson("/messages/{$thread->id}/poll")->assertForbidden();
});

test('both parties can read and post', function () {
    $thread = thread();

    foreach ([$this->buyer, $this->seller] as $user) {
        $this->actingAs($user)
            ->post("/messages/{$thread->id}", ['body' => "hello from {$user->username}"])
            ->assertRedirect();
    }

    expect(MarketplaceMessage::count())->toBe(2)
        ->and($thread->fresh()->last_message_at)->not->toBeNull();

    $this->actingAs($this->buyer)->get("/messages/{$thread->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('marketplace/thread')
            ->has('messages', 2));
});

test('the poll returns only what the viewer has not seen', function () {
    $thread = thread();

    $first = MarketplaceMessage::factory()->create([
        'marketplace_thread_id' => $thread->id, 'sender_id' => $this->seller->id,
    ]);
    $second = MarketplaceMessage::factory()->create([
        'marketplace_thread_id' => $thread->id, 'sender_id' => $this->seller->id,
    ]);

    $this->actingAs($this->buyer)
        ->getJson("/messages/{$thread->id}/poll?after={$first->id}")
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.id', $second->id);
});

test('the inbox shows only the viewer\'s own conversations', function () {
    thread();

    $other = MarketplaceThread::factory()->create([
        'marketplace_listing_id' => MarketplaceListing::factory()->create(['user_id' => $this->seller->id]),
        'buyer_id' => User::factory(),
        'seller_id' => User::factory(),
    ]);

    $this->actingAs($this->buyer)->get('/messages')
        ->assertInertia(fn (Assert $page) => $page
            ->component('marketplace/inbox')
            ->has('threads', 1));

    expect($other->buyer_id)->not->toBe($this->buyer->id);
});

test('an unread thread is marked unread until it is opened', function () {
    $thread = thread();

    $this->actingAs($this->seller)->post("/messages/{$thread->id}", ['body' => 'still there?']);

    $this->actingAs($this->buyer)->get('/messages')
        ->assertInertia(fn (Assert $page) => $page->where('threads.0.unread', true));

    $this->actingAs($this->buyer)->get("/messages/{$thread->id}")->assertOk();

    $this->actingAs($this->buyer)->get('/messages')
        ->assertInertia(fn (Assert $page) => $page->where('threads.0.unread', false));
});

test('a deal can be logged, agreed and confirmed through the routes', function () {
    $thread = thread();

    $this->actingAs($this->buyer)
        ->post("/messages/{$thread->id}/deal", ['price_cents' => 75000])
        ->assertRedirect()->assertSessionHas('success');

    $deal = MarketplaceDeal::firstOrFail();
    expect($deal->status)->toBe(DealStatus::Proposed);

    $this->actingAs($this->seller)->post("/deals/{$deal->id}/act", ['action' => 'accept'])->assertRedirect();
    expect($deal->fresh()->status)->toBe(DealStatus::Accepted);

    $this->actingAs($this->buyer)->post("/deals/{$deal->id}/act", ['action' => 'confirm'])->assertRedirect();
    $this->actingAs($this->seller)->post("/deals/{$deal->id}/act", ['action' => 'confirm'])->assertRedirect();

    expect($deal->fresh()->status)->toBe(DealStatus::Complete)
        ->and($this->seller->fresh()->direct_deal_count)->toBe(1);
});

test('a stranger cannot act on somebody else\'s deal', function () {
    $thread = thread();
    $stranger = User::factory()->create();

    $this->actingAs($this->buyer)->post("/messages/{$thread->id}/deal", ['price_cents' => 75000]);
    $deal = MarketplaceDeal::firstOrFail();

    $this->actingAs($stranger)->post("/deals/{$deal->id}/act", ['action' => 'accept'])->assertForbidden();
    $this->actingAs($stranger)->post("/deals/{$deal->id}/rate", ['rating' => 5])->assertForbidden();
});

test('rating a completed deal records it and moves the average', function () {
    $deal = MarketplaceDeal::factory()->complete()->create([
        'marketplace_listing_id' => $this->listing->id,
        'buyer_id' => $this->buyer->id,
        'seller_id' => $this->seller->id,
        'proposed_by_id' => $this->buyer->id,
    ]);

    $this->actingAs($this->buyer)
        ->post("/deals/{$deal->id}/rate", ['rating' => 4, 'comment' => 'Good comms.'])
        ->assertRedirect()->assertSessionHas('success');

    expect((float) $this->seller->fresh()->avg_rating)->toBe(4.0);
});

test('the error from a refused deal is shown, not thrown', function () {
    // One open deal per thread; the second attempt has to come back as a message
    // the person can read rather than a 500.
    $thread = thread();

    $this->actingAs($this->buyer)->post("/messages/{$thread->id}/deal", ['price_cents' => 75000]);

    $this->actingAs($this->seller)
        ->post("/messages/{$thread->id}/deal", ['price_cents' => 60000])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(MarketplaceDeal::count())->toBe(1);
});

test('guests are sent to sign in', function () {
    $thread = thread();

    $this->get('/messages')->assertRedirect('/login');
    $this->get("/messages/{$thread->id}")->assertRedirect('/login');
    $this->post("/marketplace/{$this->listing->id}/contact")->assertRedirect('/login');
});
