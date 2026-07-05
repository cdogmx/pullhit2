<?php

use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\User;

test('prune removes distributor cases but keeps real products and owned items', function () {
    $sealed = fn (string $name) => CatalogItem::factory()->sealed()->create(['name' => $name]);

    // Distributor cases — should be removed.
    $boxCase = $sealed('Chaos Rising Booster Box Case');
    $etbCase = $sealed('Temporal Forces Pokemon Center Elite Trainer Box Case (Exclusive) [Iron Leaves]');
    $tinCase = $sealed('Ascended Heroes Tin Case');
    $lowerCase = $sealed('Ascended Heroes Collection case'); // lowercase

    // Real products where "case" is part of the name — should be KEPT.
    $caseFile = $sealed('Detective Pikachu Special Case File - 3 Pack Booster Blister');
    $onTheCase = $sealed('Detective Pikachu On the Case Figure Collection');
    $showcase = $sealed('Team Rocket Showcase Box'); // "case" is a substring, not a word
    $plainBox = $sealed('Chaos Rising Booster Box');

    // A case a collector actually owns — must NOT be deleted.
    $ownedCase = $sealed('151 Booster Box Case');
    CollectionItem::factory()->for(User::factory())->for($ownedCase)->create();

    $this->artisan('sealed:prune-cases')->assertSuccessful();

    // Removed:
    foreach ([$boxCase, $etbCase, $tinCase, $lowerCase] as $gone) {
        $this->assertDatabaseMissing('catalog_items', ['id' => $gone->id]);
    }

    // Kept:
    foreach ([$caseFile, $onTheCase, $showcase, $plainBox, $ownedCase] as $kept) {
        $this->assertDatabaseHas('catalog_items', ['id' => $kept->id]);
    }
});

test('the dry run deletes nothing', function () {
    $case = CatalogItem::factory()->sealed()->create(['name' => 'Surging Sparks Booster Box Case']);

    $this->artisan('sealed:prune-cases --dry-run')->assertSuccessful();

    $this->assertDatabaseHas('catalog_items', ['id' => $case->id]);
});
