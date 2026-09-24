<?php

use App\Models\GradePrediction;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->forceFill(['is_admin' => true])->save();
});

test('saving a run keeps the reading and throws the photographs away', function () {
    // The images are megabytes apiece and the thing worth keeping is the
    // reading, not the picture of it.
    $this->actingAs($this->admin)
        ->postJson('/admin/grade-predictor/predictions', [
            'label' => 'Milotic ex 237/191',
            'sides' => [
                'front' => [
                    'usable' => true,
                    'surface' => ['score' => 910],
                    'images' => ['albedo' => 'data:image/png;base64,AAAA'],
                ],
            ],
            'estimate' => ['score' => 874, 'probs' => ['10' => 0.3]],
            'observed' => ['centering'],
            'guides_source' => 'manual',
        ])
        ->assertOk();

    $saved = GradePrediction::sole();

    expect($saved->label)->toBe('Milotic ex 237/191')
        ->and($saved->sides['front']['surface']['score'])->toBe(910)
        ->and($saved->sides['front'])->not->toHaveKey('images');
});

test('recording the real grade says what we gave it', function () {
    // The number the whole bench exists to produce: on the card that came back
    // a 10, what probability did we put on a 10?
    $prediction = GradePrediction::factory()->create([
        'user_id' => $this->admin->id,
        'estimate' => ['score' => 900, 'probs' => ['10' => 0.42, '9' => 0.4, 'other' => 0.18]],
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/admin/grade-predictor/predictions/{$prediction->id}", [
            'actual_company' => 'TAG',
            'actual_grade' => 10,
            'actual_cert' => 'D7145734',
        ])
        ->assertOk()
        ->assertJsonPath('probability_of_actual', 0.42);

    expect($prediction->fresh()->graded_at)->not->toBeNull();
});

test('a half grade falls where the distribution puts it, rather than inventing a bucket', function () {
    // TAG's Griffey came back 8.5. We price 10, 9 and 8, so there is no "8.5"
    // to read — and answering with the 8 bucket would overstate what we said.
    $prediction = GradePrediction::factory()->create([
        'user_id' => $this->admin->id,
        'actual_grade' => 8.5,
        'estimate' => ['probs' => ['10' => 0.2, '9' => 0.3, '8' => 0.4, 'other' => 0.1]],
    ]);

    expect($prediction->probabilityOfActual())->toBeNull();
});

test('an accepted AI guide is not calibration data', function () {
    // A guide nobody moved measures the model's aim, not the card. Fitting the
    // centering constant against those would tune it to the wrong thing.
    GradePrediction::factory()->create([
        'user_id' => $this->admin->id, 'actual_grade' => 10, 'guides_source' => 'ai',
    ]);
    $adjusted = GradePrediction::factory()->create([
        'user_id' => $this->admin->id, 'actual_grade' => 10, 'guides_source' => 'ai-adjusted',
    ]);
    $manual = GradePrediction::factory()->create([
        'user_id' => $this->admin->id, 'actual_grade' => 9, 'guides_source' => 'manual',
    ]);
    // No outcome yet — nothing to calibrate against.
    GradePrediction::factory()->create([
        'user_id' => $this->admin->id, 'actual_grade' => null, 'guides_source' => 'manual',
    ]);

    expect(GradePrediction::calibratable()->pluck('id')->all())
        ->toEqualCanonicalizing([$adjusted->id, $manual->id]);
});

test('saving and reading back are admin-only', function () {
    $outsider = User::factory()->create(['email_verified_at' => now()]);
    $prediction = GradePrediction::factory()->create(['user_id' => $this->admin->id]);

    $this->actingAs($outsider)
        ->postJson('/admin/grade-predictor/predictions', [
            'sides' => [], 'estimate' => [], 'observed' => [],
        ])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->patchJson("/admin/grade-predictor/predictions/{$prediction->id}", ['actual_grade' => 10])
        ->assertForbidden();
});

test('a grade outside the scale is refused', function () {
    $prediction = GradePrediction::factory()->create(['user_id' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->patchJson("/admin/grade-predictor/predictions/{$prediction->id}", ['actual_grade' => 11])
        ->assertStatus(422);
});

test('saved runs are listed on the bench', function () {
    GradePrediction::factory()->create([
        'user_id' => $this->admin->id,
        'label' => 'Milotic ex 237/191',
        'actual_company' => 'TAG',
        'actual_grade' => 10,
    ]);

    $this->actingAs($this->admin)
        ->get('/admin/grade-predictor')
        ->assertInertia(fn ($page) => $page
            ->has('saved', 1)
            ->where('saved.0.label', 'Milotic ex 237/191')
            // JSON renders 10.0 as 10; the value matters, not its notation.
            ->where('saved.0.actual_grade', fn ($v) => (float) $v === 10.0)
        );
});
