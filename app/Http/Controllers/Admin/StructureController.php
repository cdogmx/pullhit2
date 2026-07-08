<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductLine;
use App\Models\SeriesMeta;
use App\Models\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin reference: the catalog hierarchy (brand → series → set → subset → card)
 * explained, plus a live tree of every brand's series and sets. "Series" is not
 * an entity — it's the `series` string on each set; sets sharing a value group
 * under one browse tile. This page is documentation for future admins.
 */
class StructureController extends Controller
{
    private const UNGROUPED = '— Ungrouped (no series) —';

    public function index(): Response
    {
        $logosByLine = SeriesMeta::get(['product_line_id', 'name', 'logo_path'])
            ->groupBy('product_line_id');

        $brands = ProductLine::orderBy('name')->get()->map(function (ProductLine $line) use ($logosByLine) {
            $sets = Set::where('product_line_id', $line->id)
                ->orderByDesc('released_at')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'series', 'language', 'released_at']);

            $logos = ($logosByLine[$line->id] ?? collect())->pluck('logo_path', 'name');

            $series = $sets
                ->groupBy(fn (Set $s) => $s->series ?: self::UNGROUPED)
                ->map(fn ($group, $name) => [
                    'series' => $name,
                    // The real DB value (null for the ungrouped bucket) — what the
                    // rename action targets.
                    'value' => $group->first()->series ?: null,
                    'image' => $logos[$name] ?? null,
                    'grouped' => $name !== self::UNGROUPED,
                    'set_count' => $group->count(),
                    'sets' => $group->map(fn (Set $s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'slug' => $s->slug,
                        'language' => $s->language,
                        'released_at' => $s->released_at?->toDateString(),
                    ])->values()->all(),
                ])
                // Named series first (most sets first); the ungrouped bucket last.
                ->sortBy(fn ($row) => [$row['grouped'] ? 0 : 1, -$row['set_count']])
                ->values()
                ->all();

            return [
                'id' => $line->id,
                'brand' => $line->name,
                'slug' => $line->slug,
                'set_count' => $sets->count(),
                'series_count' => collect($series)->where('grouped', true)->count(),
                'series' => $series,
            ];
        });

        return Inertia::render('admin/structure', [
            'brands' => $brands,
        ]);
    }

    /**
     * Update a series across a brand in one action: rename it (renaming to an
     * existing name merges; a blank target ungroups) AND set its browse-tile
     * image. Series is just the sets' shared string, so the rename is a bulk
     * update on `sets`; the image lives in the series_meta side-table.
     */
    public function updateSeries(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_line_id' => ['required', 'integer', 'exists:product_lines,id'],
            'from' => ['nullable', 'string', 'max:255'],
            'to' => ['nullable', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:1000'],
        ]);

        $lineId = (int) $data['product_line_id'];
        $from = ($data['from'] ?? null) ?: null;
        $to = trim((string) ($data['to'] ?? '')) ?: null;
        $logo = ($data['logo_url'] ?? null) ?: null;

        $count = 0;
        if ($from !== $to) {
            $count = Set::where('product_line_id', $lineId)
                ->when(
                    $from === null,
                    fn (Builder $q) => $q->whereNull('series'),
                    fn (Builder $q) => $q->where('series', $from),
                )
                ->update(['series' => $to]);
        }

        // Move the metadata to follow the rename; drop it when ungrouped.
        if ($from !== null && $from !== $to) {
            SeriesMeta::where('product_line_id', $lineId)->where('name', $from)->delete();
        }

        if ($to !== null) {
            SeriesMeta::updateOrCreate(
                ['product_line_id' => $lineId, 'name' => $to],
                ['logo_path' => $logo],
            );
        }

        $label = $to ?? 'ungrouped';
        $moved = $count > 0 ? "Moved {$count} set(s) to \"{$label}\". " : '';

        return back()->with('success', $moved.'Series saved.');
    }
}
