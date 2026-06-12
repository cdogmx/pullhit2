<?php

namespace App\Actions\Import;

use App\Actions\Collection\AddToCollection;
use App\Models\CatalogItem;
use App\Models\User;

/**
 * Commit reviewed import rows into a user's collection, reusing AddToCollection
 * so each row becomes a holding + acquisition lot (cost basis, folder preserved).
 * Skips rows whose catalog item vanished. Returns the number imported.
 */
class ImportCollection
{
    public function __construct(
        protected AddToCollection $add,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows  validated importable rows
     */
    public function __invoke(User $user, array $rows): int
    {
        $imported = 0;

        foreach ($rows as $row) {
            $item = CatalogItem::find($row['catalog_item_id']);
            if ($item === null) {
                continue;
            }

            ($this->add)($user, $item, [
                'condition' => $row['condition'] ?? null,
                'grading_company_id' => $row['grading_company_id'] ?? null,
                'grade' => $row['grade'] ?? null,
                'quantity' => $row['quantity'] ?? 1,
                'unit_cost' => $row['unit_cost'] ?? 0,
                'acquired_at' => $row['acquired_at'] ?? null,
                'folder' => $row['folder'] ?? null,
                'notes' => $row['notes'] ?? null,
                'source' => 'PriceCharting import',
            ]);

            $imported++;
        }

        return $imported;
    }
}
