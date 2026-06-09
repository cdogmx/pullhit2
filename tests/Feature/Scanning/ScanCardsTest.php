<?php

use App\Actions\Scanning\ScanCards;
use App\Models\CatalogItem;
use App\Models\User;
use App\Support\Membership\ScanQuota;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(fakeAnthropic());
    $this->user = User::factory()->create();
    // A catalog match for the faked identification.
    CatalogItem::factory()->create([
        'name' => 'Pikachu ex',
        'number' => '276/217',
        'attributes' => ['language' => 'en', 'rarity' => 'SIR', 'variant' => 'holofoil'],
    ]);
});

test('a single scan identifies one card, matches it, and meters one card', function () {
    $result = app(ScanCards::class)($this->user, tinyJpeg(), 'image/jpeg', 'single');

    expect($result['detected'])->toHaveCount(1)
        ->and($result['detected'][0]['identified']['name'])->toBe('Pikachu ex')
        ->and($result['detected'][0]['candidates'])->not->toBeEmpty()
        ->and($result['usage']['used'])->toBe(1);

    expect(ScanQuota::for($this->user)->used())->toBe(1);
});

test('a bulk scan detects, crops, identifies each card, and meters per card', function () {
    $result = app(ScanCards::class)($this->user, tinyJpeg(300, 200), 'image/jpeg', 'bulk');

    // The fake detects two boxes → two crops → two identifies.
    expect($result['detected'])->toHaveCount(2)
        ->and($result['detected'][0]['thumbnail'])->toStartWith('data:image/jpeg;base64,')
        ->and($result['usage']['used'])->toBe(2);
});
