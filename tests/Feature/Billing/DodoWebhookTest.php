<?php

use App\Enums\MembershipTier;
use App\Models\User;
use App\Notifications\PaymentReceipt;
use App\Support\Billing\DodoWebhookVerifier;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.dodo.webhook_secret' => 'whsec_'.base64_encode('test-signing-secret')]);
});

/** Post a payload signed over the exact JSON Laravel will send (postJson uses json_encode). */
function postDodo(array $payload, ?array $headers = null): TestResponse
{
    $headers ??= dodoSigned($payload)['headers'];

    return test()->postJson('/api/webhooks/dodo', $payload, $headers);
}

test('the verifier accepts a correct signature and rejects tampering', function () {
    $verifier = app(DodoWebhookVerifier::class);
    $signed = dodoSigned(['type' => 'subscription.active', 'data' => []]);
    $h = $signed['headers'];

    expect($verifier->verify($signed['body'], $h['webhook-id'], $h['webhook-timestamp'], $h['webhook-signature']))->toBeTrue()
        ->and($verifier->verify($signed['body'].'x', $h['webhook-id'], $h['webhook-timestamp'], $h['webhook-signature']))->toBeFalse()
        ->and($verifier->verify($signed['body'], $h['webhook-id'], (string) (time() - 9999), $h['webhook-signature']))->toBeFalse();
});

test('a signed subscription.active webhook upgrades the user to premium', function () {
    $user = User::factory()->create();

    postDodo([
        'type' => 'subscription.active',
        'data' => [
            'subscription_id' => 'sub_123',
            'metadata' => ['user_id' => (string) $user->id],
            'next_billing_date' => '2026-07-09T00:00:00Z',
        ],
    ])->assertOk();

    $user->refresh();
    expect($user->membership_tier)->toBe(MembershipTier::Collector)
        ->and($user->dodo_subscription_id)->toBe('sub_123')
        ->and($user->membership_renews_at)->not->toBeNull();
});

test('the activated tier comes from the checkout metadata', function () {
    $user = User::factory()->create();

    postDodo([
        'type' => 'subscription.active',
        'data' => [
            'subscription_id' => 'sub_g',
            'metadata' => ['user_id' => (string) $user->id, 'tier' => 'guru'],
        ],
    ])->assertOk();

    expect($user->fresh()->membership_tier)->toBe(MembershipTier::Guru);
});

test('a credit-pack payment grants scan credits without changing tier', function () {
    $user = User::factory()->create();

    postDodo([
        'type' => 'payment.succeeded',
        'data' => [
            'metadata' => ['user_id' => (string) $user->id, 'type' => 'credits', 'credits' => '500'],
        ],
    ])->assertOk();

    $user->refresh();
    expect($user->purchased_scan_credits)->toBe(500)
        ->and($user->membership_tier)->toBe(MembershipTier::Free);
});

test('a successful payment emails a receipt', function () {
    Notification::fake();
    $user = User::factory()->create();

    postDodo([
        'type' => 'payment.succeeded',
        'data' => [
            'payment_id' => 'pay_rcpt_1',
            'total_amount' => 1999,
            'currency' => 'USD',
            'metadata' => ['user_id' => (string) $user->id, 'tier' => 'guru'],
        ],
    ])->assertOk();

    Notification::assertSentTo($user, PaymentReceipt::class);
});

test('a signed subscription.cancelled webhook downgrades to free', function () {
    $user = User::factory()->create([
        'membership_tier' => MembershipTier::Collector,
        'dodo_subscription_id' => 'sub_123',
        'membership_cancel_scheduled' => true,
    ]);

    postDodo([
        'type' => 'subscription.cancelled',
        'data' => ['subscription_id' => 'sub_123', 'metadata' => ['user_id' => (string) $user->id]],
    ])->assertOk();

    $user->refresh();
    expect($user->membership_tier)->toBe(MembershipTier::Free)
        ->and($user->membership_cancel_scheduled)->toBeFalse()
        ->and($user->dodo_subscription_id)->toBeNull();
});

test('a bad signature is rejected with 401', function () {
    $user = User::factory()->create();
    $payload = ['type' => 'subscription.active', 'data' => ['metadata' => ['user_id' => (string) $user->id]]];
    $headers = dodoSigned($payload)['headers'];
    $headers['webhook-signature'] = 'v1,not-a-valid-signature';

    postDodo($payload, $headers)->assertStatus(401);
    expect($user->fresh()->membership_tier)->toBe(MembershipTier::Free);
});

test('a duplicate webhook-id is processed once (idempotent)', function () {
    $user = User::factory()->create();
    $payload = [
        'type' => 'subscription.active',
        'data' => ['subscription_id' => 'sub_9', 'metadata' => ['user_id' => (string) $user->id]],
    ];
    $headers = dodoSigned($payload)['headers'];

    postDodo($payload, $headers)->assertOk();
    postDodo($payload, $headers)->assertJsonPath('duplicate', true);
});

test('an unresolvable user is a no-op 200', function () {
    postDodo([
        'type' => 'subscription.active',
        'data' => ['metadata' => ['user_id' => '999999']],
    ])->assertOk();
});
