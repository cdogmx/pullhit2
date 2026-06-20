<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScanFeedback;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin review of scan-detection feedback — accuracy by source (cache vs AI) and
 * the individual reports, so the cache + AI matching can be tuned from real misses.
 * Read-only; wrong cache hits already self-heal at report time.
 */
class ScanFeedbackController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'source' => (string) $request->query('source', ''),
            'result' => (string) $request->query('result', ''),
        ];

        $request->validate([
            'source' => ['nullable', Rule::in(['cache', 'vision'])],
            'result' => ['nullable', Rule::in(['correct', 'wrong'])],
        ]);

        $query = ScanFeedback::query()
            ->with(['user:id,name', 'detectedItem:id,name,number', 'correctedItem:id,name,number'])
            ->when($filters['source'] !== '', fn (Builder $q) => $q->where('source', $filters['source']))
            ->when($filters['result'] === 'correct', fn (Builder $q) => $q->where('was_correct', true))
            ->when($filters['result'] === 'wrong', fn (Builder $q) => $q->where('was_correct', false));

        $paginator = $query->latest()->paginate(40)->withQueryString();

        return Inertia::render('admin/scan-feedback', [
            'feedback' => collect($paginator->items())->map(fn (ScanFeedback $f) => $this->row($f)),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'stats' => $this->stats(),
        ]);
    }

    /** Accuracy per source. */
    private function stats(): array
    {
        return collect(['cache', 'vision'])->map(function (string $source) {
            $total = ScanFeedback::where('source', $source)->count();
            $correct = ScanFeedback::where('source', $source)->where('was_correct', true)->count();

            return [
                'source' => $source,
                'total' => $total,
                'correct' => $correct,
                'wrong' => $total - $correct,
                'accuracy' => $total > 0 ? round($correct / $total * 100) : null,
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function row(ScanFeedback $f): array
    {
        $identified = $f->identified ?? [];

        return [
            'id' => $f->id,
            'source' => $f->source,
            'was_correct' => $f->was_correct,
            'user' => $f->user?->name,
            'identified' => trim((string) ($identified['name'] ?? '').' '.($identified['number'] ?? '')),
            'detected' => $f->detectedItem
                ? trim($f->detectedItem->name.' '.($f->detectedItem->number ?? ''))
                : null,
            'corrected' => $f->correctedItem
                ? trim($f->correctedItem->name.' '.($f->correctedItem->number ?? ''))
                : null,
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }
}
