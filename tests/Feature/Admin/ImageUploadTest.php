<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->forceFill(['is_admin' => true])->save();
    Storage::fake('s3');
});

test('an admin can upload an image file into our bucket', function () {
    $file = UploadedFile::fake()->image('box.jpg', 240, 240);

    $response = $this->actingAs($this->admin)->post('/admin/images', ['file' => $file]);

    $response->assertOk()->assertJsonStructure(['url']);
    expect($response->json('url'))->toContain('phb/uploads/')
        ->and(Storage::disk('s3')->allFiles('phb/uploads'))->toHaveCount(1);
});

test('an admin can store an image from a URL (no hot-linking)', function () {
    Http::fake([
        'example.com/*' => Http::response('FAKE-PNG-BYTES', 200, ['Content-Type' => 'image/png']),
    ]);

    $response = $this->actingAs($this->admin)
        ->post('/admin/images', ['url' => 'https://example.com/box.png']);

    $response->assertOk();
    $files = Storage::disk('s3')->allFiles('phb/uploads');
    expect($files)->toHaveCount(1)
        ->and($files[0])->toEndWith('.png');
});

test('it requires a file or a url', function () {
    $this->actingAs($this->admin)
        ->post('/admin/images', [])
        ->assertSessionHasErrors(['file', 'url']);
});

test('a non-admin cannot upload images', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->post('/admin/images', ['url' => 'https://example.com/x.png'])
        ->assertForbidden();
});
