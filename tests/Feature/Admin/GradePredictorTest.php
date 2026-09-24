<?php

use App\Models\User;
use App\Support\Grading\PhotoSequence;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->forceFill(['is_admin' => true])->save();
});

/** A photo of a card: a light rectangle on a dark ground, with a glare band. */
function cardPhoto(int $w, int $h, int $glareX, string $name): UploadedFile
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 20, 20, 20));

    // The card.
    imagefilledrectangle($img, 40, 30, $w - 40, $h - 30, imagecolorallocate($img, 200, 200, 200));

    // The highlight, which is the whole signal — it has to MOVE between frames.
    for ($x = max(41, $glareX - 18); $x < min($w - 40, $glareX + 18); $x++) {
        for ($y = 31; $y < $h - 30; $y++) {
            imagesetpixel($img, $x, $y, imagecolorallocate($img, 255, 255, 255));
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'gp').'.png';
    imagepng($img, $path);
    imagedestroy($img);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

test('the bench is admin-only', function () {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get('/admin/grade-predictor')
        ->assertForbidden();

    $this->actingAs($this->admin)->get('/admin/grade-predictor')->assertOk();
});

test('one photo is refused, because one photo carries no surface information', function () {
    // The whole method is differencing frames against each other. A single
    // image cannot be differenced, and answering it with a score would be
    // inventing one.
    $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'photos' => [cardPhoto(300, 400, 100, 'a.png')],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Two or more photos — one image carries no surface information.');
});

test('photos of different sizes are refused with a message, not a 500', function () {
    // The warper differences frames pixel-for-pixel, so a mismatched frame is a
    // capture mistake to explain — the most likely one a person will make.
    $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'photos' => [
                cardPhoto(300, 400, 100, 'a.png'),
                cardPhoto(320, 400, 160, 'b.png'),
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'same size'));
});

test('a sequence with a moving highlight runs the pipeline and returns a distribution', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'photos' => [
                cardPhoto(300, 400, 90, 'a.png'),
                cardPhoto(300, 400, 150, 'b.png'),
                cardPhoto(300, 400, 210, 'c.png'),
            ],
            'canvas_width' => 200,
        ])
        ->assertOk();

    $response->assertJsonStructure([
        'usable', 'frames_used', 'specular_range', 'surface', 'estimate',
        'observed', 'images' => ['albedo', 'detail', 'frames'], 'took_ms',
    ]);

    // Always a distribution, never a grade.
    expect($response->json('estimate.probs'))->toBeArray()->not->toBeEmpty();

    // Corners and edges have no detector at all, so they must come back as
    // unseen — and unseen costs score rather than being ignored.
    expect($response->json('estimate.unseen'))->toContain('corners')
        ->and($response->json('estimate.unseen'))->toContain('edges');

    // The maps have to reach a screen: a detail map showing artwork is how you
    // tell misalignment from a scratched card, and it looks the same in numbers.
    expect($response->json('images.albedo'))->toStartWith('data:image/png;base64,')
        ->and($response->json('images.detail'))->toStartWith('data:image/png;base64,');
});

test('a still highlight reports unusable rather than a clean card', function () {
    // Identical frames difference to nothing. That reads as a flawless surface
    // unless it is called out, which is the worst possible failure here.
    $frames = [
        cardPhoto(300, 400, 150, 'a.png'),
        cardPhoto(300, 400, 150, 'b.png'),
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', ['photos' => $frames, 'canvas_width' => 200])
        ->assertOk();

    expect($response->json('usable'))->toBeFalse()
        // Nothing was observed, so surface must not appear as evidence.
        ->and($response->json('observed'))->not->toContain('surface');
});

test('centering is measured only when somebody marks the inner frame', function () {
    $photos = [
        cardPhoto(300, 400, 90, 'a.png'),
        cardPhoto(300, 400, 180, 'b.png'),
    ];

    $without = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', ['photos' => $photos, 'canvas_width' => 200])
        ->assertOk();

    expect($without->json('centering'))->toBeNull();

    $with = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'photos' => [
                cardPhoto(300, 400, 90, 'a.png'),
                cardPhoto(300, 400, 180, 'b.png'),
            ],
            'canvas_width' => 200,
            'inner' => ['left' => 0.08, 'right' => 0.06, 'top' => 0.07, 'bottom' => 0.07],
        ])
        ->assertOk();

    expect($with->json('centering.score'))->toBeInt()
        ->and($with->json('estimate.unseen'))->not->toContain('centering');
});

test('a luma map becomes a PNG that can actually be looked at', function () {
    $map = array_fill(0, 4, 0.0);
    $map[3] = 255.0;

    expect(PhotoSequence::toDataUri($map, 2, 2))->toStartWith('data:image/png;base64,');
});
