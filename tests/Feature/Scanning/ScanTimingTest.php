<?php

use App\Actions\Scanning\ScanCards;
use App\Models\CatalogItem;
use App\Models\ScanFingerprint;
use App\Models\ScanLog;
use App\Models\User;
use App\Support\Scanning\PerceptualHash;
use App\Support\Scanning\ScanTimer;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(fakeAnthropic());
    $this->user = User::factory()->create();
    CatalogItem::factory()->create([
        'name' => 'Pikachu ex',
        'number' => '276/217',
        'attributes' => ['language' => 'en', 'rarity' => 'SIR', 'variant' => 'holofoil'],
    ]);
});

function latestLog(): ScanLog
{
    return ScanLog::latest('id')->first();
}

test('a scan records how long it took and where the time went', function () {
    app(ScanCards::class)($this->user, tinyJpeg(), 'image/jpeg', 'single');

    $log = latestLog();

    expect($log->duration_ms)->toBeInt()
        ->and($log->identify_ms)->toBeInt()
        ->and($log->fingerprint_ms)->toBeInt()
        ->and($log->match_ms)->toBeInt()
        ->and($log->image_bytes)->toBeGreaterThan(0);

    // The phases are parts of the whole, so none of them can exceed it.
    expect($log->identify_ms)->toBeLessThanOrEqual($log->duration_ms)
        ->and($log->fingerprint_ms)->toBeLessThanOrEqual($log->duration_ms);
});

test('a phase that did not run is null, not zero', function () {
    // Single mode never detects cards — only bulk does. Storing 0 there would
    // read as "detection was instant" and quietly drag any average down.
    app(ScanCards::class)($this->user, tinyJpeg(), 'image/jpeg', 'single');

    expect(latestLog()->detect_ms)->toBeNull();
});

test('a scan answered from cache records no identify time', function () {
    // The distinction the whole exercise exists to measure: a cache hit should
    // show up as an absent vision read, not a fast one.
    $item = CatalogItem::first();
    $phash = PerceptualHash::fromBinary(base64_decode(tinyJpeg()));
    ScanFingerprint::factory()->create(['catalog_item_id' => $item->id, 'phash' => $phash]);

    app(ScanCards::class)($this->user, tinyJpeg(), 'image/jpeg', 'single');

    $log = latestLog();

    expect($log->cache_hits)->toBe(1)
        ->and($log->identify_ms)->toBeNull()
        ->and($log->fingerprint_ms)->toBeInt();
});

test('a second scan in one request is not charged the first one time', function () {
    app(ScanCards::class)($this->user, tinyJpeg(), 'image/jpeg', 'single');
    $first = latestLog();

    app(ScanCards::class)($this->user, tinyJpeg(), 'image/jpeg', 'single');
    $second = latestLog();

    // The timer is shared across the action and the strategy, so without a
    // reset the second scan would report the sum of both.
    expect($second->id)->not->toBe($first->id)
        ->and($second->identify_ms)->toBeLessThanOrEqual($second->duration_ms);
});

test('the timer still records a phase that throws', function () {
    $timer = new ScanTimer;

    expect(fn () => $timer->time('identify', function () {
        usleep(2000);
        throw new RuntimeException('vision died');
    }))->toThrow(RuntimeException::class);

    // A scan that fails slowly is exactly the one worth seeing in the data.
    expect($timer->ms('identify'))->toBeInt()->toBeGreaterThanOrEqual(1);
});

test('a phase measured more than once accumulates', function () {
    $timer = new ScanTimer;
    $timer->time('fingerprint', fn () => usleep(2000));
    $timer->time('fingerprint', fn () => usleep(2000));

    // Bulk crops every card in turn; the useful number is the total.
    expect($timer->ms('fingerprint'))->toBeGreaterThanOrEqual(3)
        ->and($timer->ms('detect'))->toBeNull();
});
