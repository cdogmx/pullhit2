<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\Giveaway;
use App\Models\User;
use App\Support\Community\Level;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public community rankings — all-time leaders (by lifetime points) and this
 * month's leaders (by points earned this calendar month, i.e. giveaway entries).
 */
class RankingsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $size = (int) config('community.leaderboard_size', 25);

        return Inertia::render('rankings', [
            'allTime' => $this->allTime($size),
            'monthly' => $this->monthly($size),
            'month' => now()->format('F Y'),
            'me' => $this->me($request->user()),
            'giveaway' => $this->currentGiveaway($request->user()),
            'pastWinners' => $this->pastWinners(),
        ]);
    }

    /** The open giveaway for this month, with prize + the viewer's entries. */
    private function currentGiveaway(?User $user): ?array
    {
        $giveaway = Giveaway::current();

        if (! $giveaway) {
            return null;
        }

        return [
            'title' => $giveaway->title,
            'prize' => $giveaway->prize,
            'description' => $giveaway->description,
            'period_label' => $giveaway->periodLabel(),
            'total_entries' => $this->poolEntries($giveaway),
            'my_entries' => $user?->monthlyEntries() ?? 0,
        ];
    }

    /** Recent drawn giveaways with their winners. */
    private function pastWinners(): array
    {
        return Giveaway::drawn()
            ->whereNotNull('winner_user_id')
            ->with('winner:id,username,name')
            ->orderByDesc('period')
            ->take(6)
            ->get()
            ->map(fn (Giveaway $g) => [
                'period_label' => $g->periodLabel(),
                'prize' => $g->prize,
                'winner' => $g->winner?->username ?? $g->winner?->name,
            ])
            ->all();
    }

    /** Total eligible entries (points by username'd users) in a giveaway's month. */
    private function poolEntries(Giveaway $giveaway): int
    {
        return (int) Contribution::query()
            ->whereBetween('contributions.created_at', [$giveaway->periodStart(), $giveaway->periodEnd()])
            ->join('users', 'users.id', '=', 'contributions.user_id')
            ->whereNotNull('users.username')
            ->where('contributions.points', '>', 0)
            ->sum('contributions.points');
    }

    /** Top contributors by lifetime points. */
    private function allTime(int $size): array
    {
        return User::where('contribution_points', '>', 0)
            ->whereNotNull('username')
            ->orderByDesc('contribution_points')
            ->take($size)
            ->get(['username', 'contribution_points'])
            ->map(fn (User $u, int $i) => [
                'rank' => $i + 1,
                'username' => $u->username,
                'points' => (int) $u->contribution_points,
                'level' => Level::for((int) $u->contribution_points)['name'],
            ])
            ->all();
    }

    /** Top contributors by points earned this month (= giveaway entries). */
    private function monthly(int $size): array
    {
        return Contribution::query()
            ->where('contributions.created_at', '>=', now()->startOfMonth())
            ->join('users', 'users.id', '=', 'contributions.user_id')
            ->whereNotNull('users.username')
            ->groupBy('users.id', 'users.username')
            ->orderByDesc('entries')
            ->take($size)
            ->get([
                'users.username',
                DB::raw('SUM(contributions.points) as entries'),
            ])
            ->map(fn ($row, int $i) => [
                'rank' => $i + 1,
                'username' => $row->username,
                'entries' => (int) $row->entries,
            ])
            ->all();
    }

    /** The signed-in user's own standing. */
    private function me(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $points = (int) $user->contribution_points;

        return [
            'username' => $user->username,
            'points' => $points,
            'level' => $user->level(),
            'entries' => $user->monthlyEntries(),
            'rank' => $points > 0
                ? User::where('contribution_points', '>', $points)->count() + 1
                : null,
        ];
    }
}
