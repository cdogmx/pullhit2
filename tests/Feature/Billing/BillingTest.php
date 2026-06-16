<?php

use App\Actions\Billing\CancelSubscription;
use App\Actions\Billing\StartCheckout;
use App\Enums\MembershipTier;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.dodo.key' => 'test-key',
        'services.dodo.base_url' => 'https://test.dodopayments.com',
        'services.dodo.premium_product_id' => 'prod_premium',
    ]);
});

test('StartCheckout creates a Dodo checkout with the user id + email and returns its url', function () {
    Http::fake([
        '*dodopayments.com/checkouts' => Http::response(['checkout_url' => 'https://test.dodopayments.com/c/abc'], 200),
    ]);

    $user = User::factory()->create(['email' => 'buyer@example.com']);
    $url = app(StartCheckout::class)($user);

    expect($url)->toBe('https://test.dodopayments.com/c/abc');

    Http::assertSent(function ($request) use ($user) {
        return str_contains($request->url(), '/checkouts')
            && data_get($request->data(), 'metadata.user_id') === (string) $user->id
            && data_get($request->data(), 'customer.email') === 'buyer@example.com'
            && data_get($request->data(), 'product_cart.0.product_id') === 'prod_premium';
    });
});

test('the checkout endpoint redirects to the hosted checkout', function () {
    Http::fake([
        '*dodopayments.com/checkouts' => Http::response(['checkout_url' => 'https://test.dodopayments.com/c/xyz'], 200),
    ]);

    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->post('/settings/billing/checkout')
        ->assertRedirect('https://test.dodopayments.com/c/xyz');
});

test('CancelSubscription schedules cancellation at period end', function () {
    Http::fake(['*dodopayments.com/subscriptions/*' => Http::response([], 200)]);

    $user = User::factory()->create([
        'membership_tier' => MembershipTier::Collector,
        'dodo_subscription_id' => 'sub_55',
    ]);

    expect(app(CancelSubscription::class)($user))->toBeTrue();
    expect($user->fresh()->membership_cancel_scheduled)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/subscriptions/sub_55')
        && $request->method() === 'PATCH'
        && data_get($request->data(), 'cancel_at_next_billing_date') === true);
});

test('CancelSubscription is a no-op without a subscription', function () {
    Http::fake();
    $user = User::factory()->create();

    expect(app(CancelSubscription::class)($user))->toBeFalse();
    Http::assertNothingSent();
});

test('the billing page requires auth and renders the current plan', function () {
    $this->get('/settings/billing')->assertRedirect('/login');

    $user = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($user)->get('/settings/billing')->assertOk();
});
