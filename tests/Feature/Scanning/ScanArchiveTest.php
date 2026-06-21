<?php

use App\Models\User;
use App\Support\Scanning\ScanArchive;
use Illuminate\Support\Facades\Storage;

test('it stores a thumbnail and summarizes the detections', function () {
    Storage::fake('s3');
    $user = User::factory()->create();

    $detected = [[
        'identified' => ['name' => 'Pikachu', 'number' => '58'],
        'source' => 'vision',
        'candidates' => [[
            'card' => [
                'id' => 7,
                'display_name' => 'Pikachu',
                'number' => '58',
                'set' => ['name' => 'Base Set'],
                'image_url' => 'https://example.test/pikachu.png',
                'url' => '/pokemon/base-set/pikachu-58',
            ],
        ]],
    ]];

    $out = app(ScanArchive::class)->build($user, tinyJpeg(), $detected);

    expect($out['image_path'])->toBeString()
        ->and($out['results'][0]['name'])->toBe('Pikachu')
        ->and($out['results'][0]['source'])->toBe('vision')
        ->and($out['results'][0]['match']['id'])->toBe(7)
        ->and($out['results'][0]['match']['url'])->toBe('/pokemon/base-set/pikachu-58');

    expect(Storage::disk('s3')->allFiles("phb/scans/{$user->id}"))->toHaveCount(1);
});

test('a detection with no catalog match summarizes to a null match', function () {
    Storage::fake('s3');
    $user = User::factory()->create();

    $out = app(ScanArchive::class)->build($user, tinyJpeg(), [[
        'identified' => ['name' => 'Mystery', 'number' => '999'],
        'source' => 'vision',
        'candidates' => [],
    ]]);

    expect($out['results'][0]['match'])->toBeNull()
        ->and($out['results'][0]['name'])->toBe('Mystery');
});
