<?php

use Inertia\Testing\AssertableInertia as Assert;

test('public pages expose accurate, page-specific meta', function (string $url, string $needle) {
    $this->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.title', fn ($t) => str_contains(strtolower((string) $t), strtolower($needle)))
            ->where('meta.description', fn ($d) => filled($d)));
})->with([
    'deals' => ['/deals', 'deals'],
    'rankings' => ['/rankings', 'rankings'],
    'rip or keep' => ['/rip-or-keep', 'sensei'],
    'brand' => ['/brand', 'brand assets'],
    'terms' => ['/terms', 'terms of service'],
    'privacy' => ['/privacy', 'privacy policy'],
]);

test('browse search reflects the query in its meta + share URL', function () {
    $this->get('/browse?q=charizard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.title', fn ($t) => str_contains((string) $t, 'charizard'))
            ->where('meta.description', fn ($d) => str_contains((string) $d, 'charizard'))
            // Shared browse links keep the query so the search is reproduced.
            ->where('meta.url', fn ($u) => str_contains((string) $u, 'q=charizard')));
});

test('the bare browse landing has generic browse meta', function () {
    $this->get('/browse')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.title', fn ($t) => str_contains((string) $t, 'Browse trading cards')));
});
