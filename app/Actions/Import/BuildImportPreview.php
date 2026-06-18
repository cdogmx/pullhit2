<?php

namespace App\Actions\Import;

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use Illuminate\Support\Str;

/**
 * Parse a PriceCharting CSV and match every row to the catalog, returning a
 * review payload for the import UI: the confidently-importable rows (ready for
 * AddToCollection), tallies, and the top skipped set/language buckets so the
 * user can see what coverage we're missing. Only confident single matches are
 * importable; ambiguous/unmatched rows are reported, never guessed-in.
 */
class BuildImportPreview
{
    public function __construct(
        protected ParsePricechartingCsv $parse,
        protected MatchPricechartingRow $match,
    ) {}

    /**
     * @return array{token: string, importable: list<array<string, mixed>>, counts: array<string, int>, skipped: list<array{bucket: string, count: int}>}
     */
    public function __invoke(string $csv): array
    {
        $rows = ($this->parse)($csv);
        $companyIds = GradingCompany::pluck('id', 'slug')->all();

        $importable = [];
        $counts = ['parsed' => count($rows), 'matched' => 0, 'ambiguous' => 0, 'unmatched' => 0];
        $skipped = [];

        foreach ($rows as $row) {
            $result = ($this->match)($row);
            $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;

            if (($result->status === 'matched' || $result->status === 'ambiguous') && $result->candidates !== []) {
                $candidates = collect($result->candidates);
                // Default to the base (normal) printing when one is present.
                $chosen = $candidates->first(fn (CatalogItem $i) => ($i->attributes['variant'] ?? 'normal') === 'normal')
                    ?? $candidates->first();

                $companyId = $row->gradingCompany ? ($companyIds[$row->gradingCompany] ?? null) : null;
                $graded = $companyId !== null && $row->grade !== null;

                $importable[] = [
                    'catalog_item_id' => $chosen->id,
                    'name' => $chosen->name,
                    'set' => $chosen->set?->name,
                    'number' => $chosen->number,
                    'condition' => $graded ? null : ($row->condition ?? 'NM'),
                    'grading_company_id' => $graded ? $companyId : null,
                    'grade' => $graded ? $row->grade : null,
                    'state_label' => $graded
                        ? strtoupper((string) $row->gradingCompany).' '.rtrim(rtrim(sprintf('%.1f', (float) $row->grade), '0'), '.')
                        : ($row->condition ?? 'NM'),
                    'quantity' => $row->quantity,
                    'unit_cost' => intdiv($row->costBasisCents, max(1, $row->quantity)),
                    'acquired_at' => $row->acquiredAt,
                    'folder' => $row->folder,
                    'notes' => $row->notes,
                    // When >1, the UI shows a printing picker so the user resolves it.
                    'ambiguous' => $candidates->count() > 1,
                    'candidates' => $candidates->map(fn (CatalogItem $i) => [
                        'catalog_item_id' => $i->id,
                        'label' => self::printingLabel($i),
                    ])->values()->all(),
                ];
            } elseif ($result->status === 'unmatched') {
                $bucket = $row->language.' · '.$row->setName;
                $skipped[$bucket] = ($skipped[$bucket] ?? 0) + 1;
            }
        }

        arsort($skipped);
        $skippedList = [];
        foreach (array_slice($skipped, 0, 15, true) as $bucket => $count) {
            $skippedList[] = ['bucket' => $bucket, 'count' => $count];
        }

        // A content signature so the review UI can remount its editable form
        // fresh for each distinct upload (Inertia preserves the component).
        $token = substr(md5((string) json_encode($importable)), 0, 16);

        return ['token' => $token, 'importable' => $importable, 'counts' => $counts, 'skipped' => $skippedList];
    }

    /** A human label for a printing, e.g. "Reverse Holo · Double Rare". */
    private static function printingLabel(CatalogItem $item): string
    {
        $variant = (string) ($item->attributes['variant'] ?? 'normal');
        $label = Str::headline(str_replace('_', ' ', $variant));
        $rarity = $item->attributes['rarity'] ?? null;

        return $rarity ? "{$label} · {$rarity}" : $label;
    }
}
