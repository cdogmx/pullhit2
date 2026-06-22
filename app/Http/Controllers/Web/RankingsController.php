<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
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
        ]);
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
