<?php

test('the photo guide is public', function () {
    // It is linked from shared reports, which land on people with no account.
    // Behind auth it would be a dead link for exactly the readers it is for.
    $this->get('/grading-photos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('grading/photo-guide'));
});
