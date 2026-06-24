<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Community\DrawGiveaway;
use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\Giveaway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin: create monthly giveaways and draw a weighted-random winner from that
 * month's contribution points. See App\Actions\Community\DrawGiveaway.
 */
class GiveawayController extends Controller
{
    public function index(): Response
    {
        $giveaways = Giveaway::with('winner:id,name,username')
            ->orderByDesc('period')
            ->get()
            ->map(fn (Giveaway $g) => [
                'id' => $g->id,
                'period' => $g->period,
                'period_label' => $g->periodLabel(),
                'title' => $g->title,
                'prize' => $g->prize,
                'image' => $g->image_path,
                'status' => $g->status,
                'winner' => $g->winner?->username ?? $g->winner?->name,
                'winner_entries' => $g->winner_entries,
                'total_entries' => $g->total_entries,
                'entrant_count' => $g->entrant_count,
                'drawn_at' => $g->drawn_at?->toIso8601String(),
            ]);

        // Live pool for the current month (so an admin sees the prize before drawing).
        $month = Carbon::now()->format('Y-m');
        $pool = $this->pool(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth());

        return Inertia::render('admin/giveaways', [
            'giveaways' => $giveaways,
            'currentMonth' => $month,
            'currentPool' => $pool,
            'hasCurrent' => $giveaways->contains('period', $month),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/', 'unique:giveaways,period'],
            'title' => ['required', 'string', 'max:120'],
            'prize' => ['required', 'string', 'max:160'],
            'image_path' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        Giveaway::create($validated);

        return back()->with('success', 'Giveaway created.');
    }

    public function draw(Giveaway $giveaway, DrawGiveaway $draw): RedirectResponse
    {
        if ($giveaway->status === Giveaway::DRAWN) {
            return back()->with('error', 'This giveaway has already been drawn.');
        }

        $winner = $draw($giveaway);

        return back()->with(
            'success',
            $winner
                ? "Winner drawn: {$winner->username} (notified)."
                : 'No entries for that month — nothing to draw.',
        );
    }

    public function destroy(Giveaway $giveaway): RedirectResponse
    {
        $giveaway->delete();

        return back()->with('success', 'Giveaway deleted.');
    }

    /**
     * @return array{entrants:int, entries:int}
     */
    private function pool(Carbon $start, Carbon $end): array
    {
        $rows = Contribution::query()
            ->whereBetween('contributions.created_at', [$start, $end])
            ->join('users', 'users.id', '=', 'contributions.user_id')
            ->whereNotNull('users.username')
            ->groupBy('users.id')
            ->havingRaw('SUM(contributions.points) > 0')
            ->get([DB::raw('SUM(contributions.points) as entries')]);

        return ['entrants' => $rows->count(), 'entries' => (int) $rows->sum('entries')];
    }
}
