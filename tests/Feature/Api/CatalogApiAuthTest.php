<?php

use App\Models\CatalogItem;

test('the bulk catalog search + full item payload require a token', function () {
    $item = CatalogItem::factory()->create();

    // No enumeration of the catalog / valuations without authenticating.
    $this->getJson('/api/v1/catalog')->assertUnauthorized();
    $this->getJson("/api/v1/catalog/{$item->id}")->assertUnauthorized();
});

test('the per-card reads the public web pages need stay public', function () {
    $item = CatalogItem::factory()->create();

    // These serve anonymous card pages (value poll, chart, comps, buy links).
    $this->getJson("/api/v1/catalog/{$item->id}/values")->assertOk();
    $this->getJson("/api/v1/catalog/{$item->id}/price-history")->assertOk();
    $this->getJson("/api/v1/catalog/{$item->id}/listings")->assertOk();
});
