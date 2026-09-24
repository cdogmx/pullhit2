<?php

use App\Models\User;
use App\Support\Grading\Homography;
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

test('guides measure centering on the flattened card, not in the photograph', function () {
    // A card shot square-on, with its frame deliberately off-centre: margins of
    // 0.10 left and 0.14 right inside an outline spanning 0.1..0.9.
    // left share = 0.10 / (0.10 + 0.14) = 41.7%
    $square = [
        ['x' => 0.1, 'y' => 0.1], ['x' => 0.9, 'y' => 0.1],
        ['x' => 0.9, 'y' => 0.9], ['x' => 0.1, 'y' => 0.9],
    ];
    $frame = [
        ['x' => 0.20, 'y' => 0.30], ['x' => 0.76, 'y' => 0.30],
        ['x' => 0.76, 'y' => 0.70], ['x' => 0.20, 'y' => 0.70],
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'front' => [cardPhoto(300, 400, 90, 'a.png'), cardPhoto(300, 400, 180, 'b.png')],
            'canvas_width' => 200,
            'guides' => ['front' => ['outline' => $square, 'frame' => $frame]],
        ])
        ->assertOk();

    expect($response->json('sides.front.centering.left'))->toBeGreaterThan(41.0)
        ->and($response->json('sides.front.centering.left'))->toBeLessThan(42.5)
        ->and($response->json('estimate.unseen'))->not->toContain('centering');
});

test('a card shot at an angle is not read as off-centre', function () {
    // The same perfectly centred frame, photographed at a slant. Measured in
    // the photograph this reads as a centering fault; measured on the flattened
    // card it is 50/50, which is the whole reason the frame is mapped through
    // the homography rather than measured where it was drawn.
    $slanted = [
        ['x' => 0.15, 'y' => 0.10], ['x' => 0.85, 'y' => 0.18],
        ['x' => 0.85, 'y' => 0.82], ['x' => 0.15, 'y' => 0.90],
    ];

    // Built by mapping a centred frame OUT of card space into the photo — the
    // projective way. Interpolating along the quad's edges instead (bilinear)
    // is not the same transform, and on this slant it lands at 41/59: an 80
    // point centering error from a mapping that looks plausible.
    $toPhoto = Homography::between(
        [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]],
        array_map(fn ($p) => [$p['x'], $p['y']], $slanted),
    );

    $inner = array_map(function (array $uv) use ($toPhoto) {
        [$x, $y] = $toPhoto->apply($uv[0], $uv[1]);

        return ['x' => $x, 'y' => $y];
    }, [[0.2, 0.2], [0.8, 0.2], [0.8, 0.8], [0.2, 0.8]]);

    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'front' => [cardPhoto(300, 400, 90, 'a.png'), cardPhoto(300, 400, 180, 'b.png')],
            'canvas_width' => 200,
            'guides' => ['front' => ['outline' => $slanted, 'frame' => $inner]],
        ])
        ->assertOk();

    // Dead centre, despite the slant.
    expect($response->json('sides.front.centering.left'))->toBeGreaterThan(48.0)
        ->and($response->json('sides.front.centering.left'))->toBeLessThan(52.0)
        ->and($response->json('sides.front.centering.score'))->toBeGreaterThan(970);
});

test('a frame dragged outside the card is refused, not reported', function () {
    // A misdragged guide must not produce a confident bogus ratio.
    $outline = [
        ['x' => 0.3, 'y' => 0.3], ['x' => 0.7, 'y' => 0.3],
        ['x' => 0.7, 'y' => 0.7], ['x' => 0.3, 'y' => 0.7],
    ];
    $outside = [
        ['x' => 0.0, 'y' => 0.0], ['x' => 1.0, 'y' => 0.0],
        ['x' => 1.0, 'y' => 1.0], ['x' => 0.0, 'y' => 1.0],
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'front' => [cardPhoto(300, 400, 90, 'a.png'), cardPhoto(300, 400, 180, 'b.png')],
            'canvas_width' => 200,
            'guides' => ['front' => ['outline' => $outline, 'frame' => $outside]],
        ])
        ->assertOk();

    expect($response->json('sides.front.centering'))->toBeNull()
        ->and($response->json('estimate.unseen'))->toContain('centering');
});

test('dragged guides beat a typed split, because one is a measurement', function () {
    $square = [
        ['x' => 0.1, 'y' => 0.1], ['x' => 0.9, 'y' => 0.1],
        ['x' => 0.9, 'y' => 0.9], ['x' => 0.1, 'y' => 0.9],
    ];
    $centred = [
        ['x' => 0.25, 'y' => 0.25], ['x' => 0.75, 'y' => 0.25],
        ['x' => 0.75, 'y' => 0.75], ['x' => 0.25, 'y' => 0.75],
    ];

    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'front' => [cardPhoto(300, 400, 90, 'a.png'), cardPhoto(300, 400, 180, 'b.png')],
            'canvas_width' => 200,
            'guides' => ['front' => ['outline' => $square, 'frame' => $centred]],
            'centering' => ['front' => ['left' => 30, 'right' => 70, 'top' => 50, 'bottom' => 50]],
        ])
        ->assertOk();

    // The guides say 50/50; the typed split said 30/70 and must not win.
    expect($response->json('sides.front.centering.left'))->toEqual(50);
});

test('a guide dragged by a side moves both of its corners', function () {
    // The server reads four corners; the UI offers sides as a convenience that
    // translates the two they join. This pins the shape that arrives: a quad,
    // not a rectangle, because a hand-held photo is not square-on and squaring
    // it off would throw away the perspective the homography needs.
    $skewed = [
        ['x' => 0.12, 'y' => 0.08], ['x' => 0.88, 'y' => 0.14],
        ['x' => 0.88, 'y' => 0.86], ['x' => 0.12, 'y' => 0.92],
    ];

    $toPhoto = Homography::between(
        [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]],
        array_map(fn ($p) => [$p['x'], $p['y']], $skewed),
    );

    // An inner border sitting 30/70 across on the flattened card.
    $inner = array_map(function (array $uv) use ($toPhoto) {
        [$x, $y] = $toPhoto->apply($uv[0], $uv[1]);

        return ['x' => $x, 'y' => $y];
    }, [[0.12, 0.2], [0.72, 0.2], [0.72, 0.8], [0.12, 0.8]]);

    $response = $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor', [
            'front' => [cardPhoto(300, 400, 90, 'a.png'), cardPhoto(300, 400, 180, 'b.png')],
            'canvas_width' => 200,
            'guides' => ['front' => ['outline' => $skewed, 'frame' => $inner]],
        ])
        ->assertOk();

    // left margin 0.12, right margin 0.28 -> 30% left share.
    expect($response->json('sides.front.centering.left'))->toBeGreaterThan(29.0)
        ->and($response->json('sides.front.centering.left'))->toBeLessThan(31.0);
});
