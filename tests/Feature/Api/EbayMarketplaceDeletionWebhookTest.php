<?php

test('ebay marketplace deletion challenge returns the sha256 response', function () {
    $token = 'a-test-verification-token-exactly-32ch';
    $endpoint = 'https://cardfoo.com/api/webhooks/ebay/marketplace-account-deletion';
    $challenge = 'abc123challenge';

    config([
        'services.ebay.marketplace_deletion_token' => $token,
        'services.ebay.marketplace_deletion_endpoint' => $endpoint,
    ]);

    $expected = hash('sha256', $challenge.$token.$endpoint);

    $this->getJson(
        '/api/webhooks/ebay/marketplace-account-deletion?challenge_code='.$challenge,
    )
        ->assertOk()
        ->assertExactJson(['challengeResponse' => $expected]);
});

test('ebay marketplace deletion challenge fails closed when unconfigured', function () {
    config([
        'services.ebay.marketplace_deletion_token' => null,
        'services.ebay.marketplace_deletion_endpoint' => null,
    ]);

    $this->getJson('/api/webhooks/ebay/marketplace-account-deletion?challenge_code=x')
        ->assertStatus(503);
});

test('ebay marketplace deletion notify acknowledges the payload', function () {
    $this->postJson('/api/webhooks/ebay/marketplace-account-deletion', [
        'metadata' => ['topic' => 'MARKETPLACE_ACCOUNT_DELETION'],
        'notification' => [
            'notificationId' => 'n-1',
            'data' => ['username' => 'test_user', 'userId' => 'u-1'],
        ],
    ])->assertOk()->assertJson(['ok' => true]);
});
