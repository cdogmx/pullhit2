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
