<?php

use App\Models\GradePrediction;
use App\Models\User;

function admin(string $name): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $name]);
    // username, because that is the handle the list shows — the same one a
    // public page would identify them by.
    $user->forceFill(['is_admin' => true, 'username' => $name])->save();

    return $user;
}

beforeEach(function () {
    $this->mine = admin('Mine');
    $this->theirs = admin('Theirs');

    $this->run = GradePrediction::factory()->create([
        'user_id' => $this->mine->id,
        'label' => 'Milotic ex 237/191',
    ]);
});

test('the person who ran it can rename it and leave themselves a note', function () {
    $this->actingAs($this->mine)
        ->patchJson("/admin/grade-predictor/predictions/{$this->run->id}", [
            'label' => 'Milotic ex 237/191 — second attempt',
            'notes' => 'Four tilted shots a side this time.',
        ])
        ->assertOk();

    $fresh = $this->run->fresh();

    expect($fresh->label)->toBe('Milotic ex 237/191 — second attempt')
        ->and($fresh->notes)->toBe('Four tilted shots a side this time.');
});

test('renaming a run does not un-grade it', function () {
    // The old write set graded_at from whatever the payload happened to hold,
    // so an edit that never mentioned the grade quietly cleared it. A run that
    // has come back from a grader is calibration data; losing that silently is
    // the worst kind of loss.
    $this->run->update([
        'actual_company' => 'TAG',
        'actual_grade' => 10,
        'graded_at' => now(),
    ]);

    $this->actingAs($this->mine)
        ->patchJson("/admin/grade-predictor/predictions/{$this->run->id}", [
            'label' => 'Renamed',
        ])
        ->assertOk();

    $fresh = $this->run->fresh();

    expect($fresh->actual_grade)->toBe(10.0)
        ->and($fresh->graded_at)->not->toBeNull();
});

test('somebody else cannot edit, delete, or share it', function () {
    // Every admin can SEE every run — comparing them is the point of the list.
    // Changing another person's record of their own card is not.
    $this->actingAs($this->theirs)
        ->patchJson("/admin/grade-predictor/predictions/{$this->run->id}", ['label' => 'Mine now'])
        ->assertForbidden();

    $this->actingAs($this->theirs)
        ->postJson("/admin/grade-predictor/predictions/{$this->run->id}/share")
        ->assertForbidden();

    $this->actingAs($this->theirs)
        ->deleteJson("/admin/grade-predictor/predictions/{$this->run->id}")
        ->assertForbidden();

    expect($this->run->fresh()->label)->toBe('Milotic ex 237/191')
        ->and($this->run->fresh()->share_token)->toBeNull();
});

test('the list shows every run, and marks which are yours', function () {
    GradePrediction::factory()->create([
        'user_id' => $this->theirs->id,
        'label' => 'Somebody else\'s card',
    ]);

    $this->actingAs($this->mine)
        ->get('/admin/grade-predictor')
        ->assertInertia(fn ($page) => $page
            ->has('saved', 2)
            // Newest first, so theirs leads.
            ->where('saved.0.owned', false)
            ->where('saved.0.ran_by', 'Theirs')
            ->where('saved.1.owned', true)
        );
});

test('the owner can delete their own run', function () {
    $this->actingAs($this->mine)
        ->deleteJson("/admin/grade-predictor/predictions/{$this->run->id}")
        ->assertOk();

    expect(GradePrediction::find($this->run->id))->toBeNull();
});

test('a grade can still be cleared deliberately', function () {
    // Editing must not un-grade by accident, but saying so on purpose has to
    // keep working — a grade typed in wrong needs taking back out.
    $this->run->update(['actual_grade' => 9, 'graded_at' => now()]);

    $this->actingAs($this->mine)
        ->patchJson("/admin/grade-predictor/predictions/{$this->run->id}", ['actual_grade' => null])
        ->assertOk();

    $fresh = $this->run->fresh();

    expect($fresh->actual_grade)->toBeNull()
        ->and($fresh->graded_at)->toBeNull();
});
