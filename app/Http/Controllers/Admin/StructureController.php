<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductLine;
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
        $brands = ProductLine::orderBy('name')->get()->map(function (ProductLine $line) {
            $sets = Set::where('product_line_id', $line->id)
                ->orderByDesc('released_at')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'series', 'language', 'released_at']);

            $series = $sets
                ->groupBy(fn (Set $s) => $s->series ?: self::UNGROUPED)
                ->map(fn ($group, $name) => [
                    'series' => $name,
                    // The real DB value (null for the ungrouped bucket) — what the
                    // rename action targets.
                    'value' => $group->first()->series ?: null,
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
     * Rename (or merge/ungroup) a series across every set in a brand. Since a
     * series is just the sets' shared string, renaming to an existing name merges
     * the two, and an empty target ungroups them. This is the series level's
     * "update/delete".
     */
    public function renameSeries(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_line_id' => ['required', 'integer', 'exists:product_lines,id'],
            'from' => ['nullable', 'string', 'max:255'],
            'to' => ['nullable', 'string', 'max:255'],
        ]);

        $from = ($data['from'] ?? null) ?: null;
        $to = trim((string) ($data['to'] ?? '')) ?: null;

        if ($from === $to) {
            return back();
        }

        $count = Set::where('product_line_id', $data['product_line_id'])
            ->when(
                $from === null,
                fn (Builder $q) => $q->whereNull('series'),
                fn (Builder $q) => $q->where('series', $from),
            )
            ->update(['series' => $to]);

        $label = $to ?? 'ungrouped';

        return back()->with('success', "Moved {$count} set(s) to \"{$label}\".");
    }
}
