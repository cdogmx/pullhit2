<?php

namespace App\Actions\Import;

use App\Models\GradingCompany;

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
     * @return array{importable: list<array<string, mixed>>, counts: array<string, int>, skipped: list<array{bucket: string, count: int}>}
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

            if ($result->status === 'matched' && $result->catalogItem !== null) {
                $item = $result->catalogItem;
                $companyId = $row->gradingCompany ? ($companyIds[$row->gradingCompany] ?? null) : null;
                $graded = $companyId !== null && $row->grade !== null;
                $unitCost = intdiv($row->costBasisCents, max(1, $row->quantity));

                $importable[] = [
                    'catalog_item_id' => $item->id,
                    'name' => $item->name,
                    'set' => $item->set?->name,
                    'number' => $item->number,
                    'condition' => $graded ? null : ($row->condition ?? 'NM'),
                    'grading_company_id' => $graded ? $companyId : null,
                    'grade' => $graded ? $row->grade : null,
                    'state_label' => $graded
                        ? strtoupper((string) $row->gradingCompany).' '.rtrim(rtrim(sprintf('%.1f', (float) $row->grade), '0'), '.')
                        : ($row->condition ?? 'NM'),
                    'quantity' => $row->quantity,
                    'unit_cost' => $unitCost,
                    'acquired_at' => $row->acquiredAt,
                    'folder' => $row->folder,
                    'notes' => $row->notes,
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

        return ['importable' => $importable, 'counts' => $counts, 'skipped' => $skippedList];
    }
}
