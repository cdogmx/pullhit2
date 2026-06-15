<?php

use App\Models\CatalogItem;
use App\Models\ScanFingerprint;
use App\Support\Scanning\FingerprintCache;
use App\Support\Scanning\PerceptualHash;

test('a dHash is 16 hex chars and stable for the same image', function () {
    $a = PerceptualHash::fromBinary(base64_decode(tinyJpeg(120, 168)));
    $again = PerceptualHash::fromBinary(base64_decode(tinyJpeg(120, 168)));

    expect($a)->toMatch('/^[0-9a-f]{16}$/')
        ->and($again)->toBe($a)
        ->and(PerceptualHash::distance($a, $again))->toBe(0);
});

test('distance counts differing bits', function () {
    expect(PerceptualHash::distance('0000000000000000', '0000000000000000'))->toBe(0)
        ->and(PerceptualHash::distance('0000000000000000', 'ffffffffffffffff'))->toBe(64)
        ->and(PerceptualHash::distance('0000000000000000', '0000000000000001'))->toBe(1);
});

test('non-image bytes hash to null rather than throwing', function () {
    expect(PerceptualHash::fromBinary('not an image'))->toBeNull();
});

test('the cache recognises a learned fingerprint, eager-loading the item', function () {
    $item = CatalogItem::factory()->create();
    $phash = PerceptualHash::fromBinary(base64_decode(tinyJpeg()));
    ScanFingerprint::factory()->create(['catalog_item_id' => $item->id, 'phash' => $phash]);

    $hit = app(FingerprintCache::class)->lookup($phash);

    expect($hit)->not->toBeNull()
        ->and($hit['item']->id)->toBe($item->id)
        ->and($hit['distance'])->toBe(0)
        ->and($hit['item']->relationLoaded('set'))->toBeTrue();
});

test('a far-off fingerprint is not recognised', function () {
    $item = CatalogItem::factory()->create();
    ScanFingerprint::factory()->create(['catalog_item_id' => $item->id, 'phash' => '0000000000000000']);

    expect(app(FingerprintCache::class)->lookup('ffffffffffffffff'))->toBeNull();
});

test('recording is idempotent per (hash, item) and raises confidence', function () {
    $item = CatalogItem::factory()->create();
    $cache = app(FingerprintCache::class);

    $cache->record('abcdef0123456789', $item->id, null);
    $cache->record('abcdef0123456789', $item->id, null);

    expect(ScanFingerprint::count())->toBe(1)
        ->and(ScanFingerprint::sole()->confirmations)->toBe(2);
});

test('the closer of two learned fingerprints wins', function () {
    $near = CatalogItem::factory()->create();
    $far = CatalogItem::factory()->create();
    ScanFingerprint::factory()->create(['catalog_item_id' => $near->id, 'phash' => '0000000000000003']);
    ScanFingerprint::factory()->create(['catalog_item_id' => $far->id, 'phash' => '00000000000000ff']);

    $hit = app(FingerprintCache::class)->lookup('0000000000000001');

    expect($hit['item']->id)->toBe($near->id);
});
