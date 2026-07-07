<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->for($vertical)->create(['slug' => 'one-piece']);
    $this->enSet = Set::factory()->for($this->line)->create(['slug' => 'op06-en', 'language' => 'en']);
    $this->jaSet = Set::factory()->for($this->line)->create(['slug' => 'op06-ja', 'language' => 'ja']);
});

function opCard(ProductLine $line, Set $set, string $lang, string $name = 'Absalom', string $variant = 'normal'): CatalogItem
{
    return CatalogItem::factory()->create([
        'product_line_id' => $line->id,
        'set_id' => $set->id,
        'name' => $name,
        'number' => 'OP06-081',
        'attributes' => ['language' => $lang, 'variant' => $variant],
    ]);
}

test('the card page links the same card in another language (same name + number)', function () {
    $en = opCard($this->line, $this->enSet, 'en');
    $ja = opCard($this->line, $this->jaSet, 'ja');

    $this->get("/one-piece/{$this->enSet->slug}/{$en->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('otherLanguages', 1)
            ->where('otherLanguages.0.language', 'ja')
            ->where('otherLanguages.0.url', fn ($u) => str_contains((string) $u, $ja->slug)));
});

test('Pokemon: links per-set-numbered cards across languages via a shared expansion_key', function () {
    // JP and EN restart numbering at 1 every set, so the link is the set pairing
    // (expansion_key), then integer-number + normalized-name. Mirrors the real
    // data: zero-padded JP numbers, a "- 003/083" name suffix, and a JP "holo"
    // ex whose EN twin is tagged "normal".
    $enSet = Set::factory()->for($this->line)->create(['slug' => 'chaos-rising', 'language' => 'en', 'expansion_key' => 'pkm-cr']);
    $jaSet = Set::factory()->for($this->line)->create(['slug' => 'ninja-spinner-ja', 'language' => 'ja', 'expansion_key' => 'pkm-cr']);

    $enBeedrill = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $enSet->id,
        'name' => 'Beedrill ex', 'number' => '3',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);
    CatalogItem::factory()->create([ // JP twin: padded number, noisy name, holo variant
        'product_line_id' => $this->line->id, 'set_id' => $jaSet->id,
        'name' => 'Beedrill ex - 003/083', 'number' => '003',
        'attributes' => ['language' => 'ja', 'variant' => 'holo'],
    ]);

    $this->get("/{$this->line->slug}/{$enSet->slug}/{$enBeedrill->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('otherLanguages', 1)
            ->where('otherLanguages.0.language', 'ja'));
});

test('Pokemon: a normal printing prefers the normal twin over a reverse holo of the same number', function () {
    $enSet = Set::factory()->for($this->line)->create(['slug' => 'cr-en', 'language' => 'en', 'expansion_key' => 'pkm-cr2']);
    $jaSet = Set::factory()->for($this->line)->create(['slug' => 'cr-ja', 'language' => 'ja', 'expansion_key' => 'pkm-cr2']);

    $jaWeedle = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $jaSet->id,
        'name' => 'Weedle', 'number' => '001',
        'attributes' => ['language' => 'ja', 'variant' => 'normal'],
    ]);
    $enNormal = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $enSet->id,
        'name' => 'Weedle', 'number' => '1',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);
    CatalogItem::factory()->create([ // reverse holo at the same number — must be skipped
        'product_line_id' => $this->line->id, 'set_id' => $enSet->id,
        'name' => 'Weedle', 'number' => '1',
        'attributes' => ['language' => 'en', 'variant' => 'reverse_holo'],
    ]);

    $this->get("/{$this->line->slug}/{$jaSet->slug}/{$jaWeedle->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('otherLanguages', 1)
            ->where('otherLanguages.0.url', fn ($u) => str_contains((string) $u, $enNormal->slug)));
});

test('Pokemon: per-set numbers do NOT collide across unlinked sets', function () {
    // Two sets with no shared expansion_key both have a #1 — must not link.
    $enSet = Set::factory()->for($this->line)->create(['slug' => 'set-a', 'language' => 'en']);
    $jaSet = Set::factory()->for($this->line)->create(['slug' => 'set-b', 'language' => 'ja']);

    $en = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $enSet->id,
        'name' => 'Weedle', 'number' => '1',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);
    CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $jaSet->id,
        'name' => 'Weedle', 'number' => '001',
        'attributes' => ['language' => 'ja', 'variant' => 'normal'],
    ]);

    $this->get("/{$this->line->slug}/{$enSet->slug}/{$en->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('otherLanguages', 0));
});

test('it does not match a different number, name, or the same language', function () {
    $en = opCard($this->line, $this->enSet, 'en');
    opCard($this->line, $this->enSet, 'en', 'Absalom'); // same language → excluded
    opCard($this->line, $this->jaSet, 'ja', 'Zoro');    // different card → excluded
    CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $this->jaSet->id,
        'name' => 'Absalom', 'number' => 'OP07-999',    // different number → excluded
        'attributes' => ['language' => 'ja', 'variant' => 'normal'],
    ]);

    $this->get("/one-piece/{$this->enSet->slug}/{$en->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('otherLanguages', 0));
});
