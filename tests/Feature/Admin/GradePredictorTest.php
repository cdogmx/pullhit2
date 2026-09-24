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

test('one photo is accepted but reports surface as not assessed', function () {
    // The method differences frames against each other, so a single image
    // carries no surface information. That is a fact to report, not a reason to
    // refuse the upload — the rectified card and centering still work.
    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'front' => [cardPhoto(300, 400, 100, 'a.png')],
            'canvas_width' => 200,
        ])
        ->assertOk();

    expect($response->json('sides.front.surface_assessable'))->toBeFalse()
        ->and($response->json('sides.front.surface'))->toBeNull()
        // Crucially NOT reported as a clean surface.
        ->and($response->json('observed'))->not->toContain('surface')
        ->and($response->json('estimate.unseen'))->toContain('surface')
        // The rectified card is still worth having.
        ->and($response->json('sides.front.images.frames'))->toHaveCount(1);
});

test('photos of different sizes are matched rather than refused', function () {
    // A phone crops differently between shots. The homography is fitted after
    // this, so scaling the frames to agree costs nothing and saves the person
    // shooting from fighting their camera.
    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'front' => [
                cardPhoto(300, 400, 90, 'a.png'),
                cardPhoto(320, 420, 160, 'b.png'),
            ],
            'canvas_width' => 200,
        ])
        ->assertOk();

    expect($response->json('sides.front.frames_used'))->toBe(2);
});

test('a sequence with a moving highlight returns a distribution and the maps', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'front' => [
                cardPhoto(300, 400, 90, 'a.png'),
                cardPhoto(300, 400, 150, 'b.png'),
                cardPhoto(300, 400, 210, 'c.png'),
            ],
            'canvas_width' => 200,
        ])
        ->assertOk();

    $response->assertJsonStructure([
        'sides' => ['front' => ['usable', 'frames_used', 'surface', 'images']],
        'estimate', 'observed', 'took_ms',
    ]);

    // Always a distribution, never a grade.
    expect($response->json('estimate.probs'))->toBeArray()->not->toBeEmpty();

    // Corners and edges have no detector at all, so they must come back as
    // unseen — and unseen costs score rather than being ignored.
    expect($response->json('estimate.unseen'))->toContain('corners')
        ->and($response->json('estimate.unseen'))->toContain('edges');

    // The maps have to reach a screen: a detail map showing artwork is how you
    // tell misalignment from a scratched card, and it looks the same in numbers.
    expect($response->json('sides.front.images.albedo'))->toStartWith('data:image/png;base64,')
        ->and($response->json('sides.front.images.detail'))->toStartWith('data:image/png;base64,');
});

test('a still highlight reports unusable rather than a clean card', function () {
    // Identical frames difference to nothing. That reads as a flawless surface
    // unless it is called out, which is the worst failure available here.
    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'front' => [
                cardPhoto(300, 400, 150, 'a.png'),
                cardPhoto(300, 400, 150, 'b.png'),
            ],
            'canvas_width' => 200,
        ])
        ->assertOk();

    expect($response->json('sides.front.usable'))->toBeFalse()
        // It COULD have carried surface and did not — a re-shoot, not physics.
        ->and($response->json('sides.front.surface_assessable'))->toBeTrue()
        ->and($response->json('observed'))->not->toContain('surface');
});

test('both sides are read separately', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'front' => [cardPhoto(300, 400, 90, 'a.png'), cardPhoto(300, 400, 180, 'b.png')],
            'back' => [cardPhoto(300, 400, 100, 'c.png'), cardPhoto(300, 400, 200, 'd.png')],
            'canvas_width' => 200,
        ])
        ->assertOk();

    expect($response->json('sides'))->toHaveKeys(['front', 'back']);
});

test('centering is read as a grading report writes it', function () {
    // TAG prints the Milotic (cert D7145734, GEM MINT 10) as 46L/54R 47T/53B.
    // Those four numbers are a RATIO between the margins. Read as margins they
    // describe a card of zero width — which is how the first version of this
    // form could not accept the very report it exists to be checked against.
    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'front' => [cardPhoto(300, 400, 90, 'a.png'), cardPhoto(300, 400, 180, 'b.png')],
            'canvas_width' => 200,
            'centering' => ['front' => ['left' => 46, 'right' => 54, 'top' => 47, 'bottom' => 53]],
        ])
        ->assertOk();

    expect($response->json('sides.front.centering.left'))->toEqual(46)
        // 1000 - 9.06 * 4 = 964, the same line that fits TAG's other cert.
        ->and($response->json('sides.front.centering.score'))->toBe(964)
        ->and($response->json('estimate.unseen'))->not->toContain('centering');
});

test('no photos at all is refused', function () {
    $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', ['canvas_width' => 200])
        ->assertStatus(422);
});

test('a luma map becomes a PNG that can actually be looked at', function () {
    $map = array_fill(0, 4, 0.0);
    $map[3] = 255.0;

    expect(PhotoSequence::toDataUri($map, 2, 2))->toStartWith('data:image/png;base64,');
});
