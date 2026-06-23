<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the home page renders the welcome page with catalog sections', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->has('brands')
            ->has('trending')
            ->has('movers')
            ->has('recent')
            ->has('newestSets')
            ->has('community.points')
            ->has('community.levels')
            ->where('community.month', now()->format('F')));
});
