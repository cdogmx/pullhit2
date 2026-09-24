<?php

use App\Models\GradePrediction;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->forceFill(['is_admin' => true])->save();

    $this->prediction = GradePrediction::factory()->create([
        'user_id' => $this->admin->id,
        'label' => 'Milotic ex 237/191',
        'notes' => 'private working note',
        'sides' => ['front' => [
            'usable' => true,
            'surface_assessable' => true,
            'surface' => ['score' => 910, 'bucket' => 'clean', 'defect_count' => 2],
            'centering' => ['score' => 964, 'left' => 46, 'right' => 54, 'top' => 47, 'bottom' => 53],
            'frames_used' => 3,
            'specular_range' => 41.2,
            'canvas' => ['width' => 500, 'height' => 700],
        ]],
    ]);
});

test('a prediction is private until it is shared', function () {
    // Not 403 — an unshared reading has no address at all.
    $this->get('/grade-report/whatever')->assertNotFound();

    expect($this->prediction->share_token)->toBeNull();
});

test('sharing mints a link that anybody with it can open', function () {
    $url = $this->actingAs($this->admin)
        ->postJson("/admin/grade-predictor/predictions/{$this->prediction->id}/share")
        ->assertOk()
        ->json('share_url');

    expect($url)->toContain('/grade-report/');

    // No login: the link is the permission.
    $this->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('grading/report')
            ->where('label', 'Milotic ex 237/191')
        );
});

test('the token is long enough that nobody guesses it', function () {
    $this->prediction->share();

    expect(strlen($this->prediction->fresh()->share_token))->toBe(32);
});

test('unsharing stops the old link resolving', function () {
    $url = $this->prediction->share();
    $this->get($url)->assertOk();

    $this->actingAs($this->admin)
        ->postJson("/admin/grade-predictor/predictions/{$this->prediction->id}/share", ['revoke' => true])
        ->assertOk()
        ->assertJsonPath('share_url', null);

    $this->get($url)->assertNotFound();
});

test('a shared reading carries what is needed to argue with it', function () {
    // A page that shows a number and hides how it was reached cannot be
    // debugged from. The capture figures are how a bad reading is told apart
    // from a bad card, and none of them is private.
    $url = $this->prediction->share();

    $this->get($url)->assertInertia(fn ($page) => $page
        ->where('sides.front.specular_range', 41.2)
        ->where('sides.front.frames_used', 3)
        ->where('sides.front.canvas.width', 500)
        ->has('estimate.probs')
        ->has('estimate.caveats')
        ->has('estimate.unseen')
        ->where('guides_source', 'manual')
    );
});

test('a shared reading does not carry the owner or their notes', function () {
    $url = $this->prediction->share();

    $this->get($url)->assertInertia(fn ($page) => $page
        ->missing('notes')
        ->missing('user_id')
        ->missing('user')
    );
});

test('sharing is admin-only', function () {
    $outsider = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($outsider)
        ->postJson("/admin/grade-predictor/predictions/{$this->prediction->id}/share")
        ->assertForbidden();

    expect($this->prediction->fresh()->share_token)->toBeNull();
});

test('the share meta says it is an estimate, not a grade', function () {
    // Social cards are often all anybody reads. If the framing only exists on
    // the page, the link itself still says "grade".
    $url = $this->prediction->share();

    $this->get($url)->assertInertia(fn ($page) => $page
        ->where('meta.description', fn (string $d) => str_contains($d, 'not a grade'))
    );
});

test('sharing twice keeps the same link', function () {
    $first = $this->prediction->share();
    $second = $this->prediction->fresh()->share();

    expect($second)->toBe($first);
});
