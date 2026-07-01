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
            'earn' => $this->earnMethods(),
            // The signed-in user's referral link handle (their public username).
            'referralHandle' => $request->user()?->username,
        ]);
    }

    /**
     * Every way to earn points, richest first — values come from config so the
     * copy always matches the live points. `once` flags one-time milestones.
     *
     * @return array<int, array{label: string, points: int, how: string, once: bool}>
     */
    private function earnMethods(): array
    {
        $p = fn (string $key) => (int) config("community.points.{$key}", 0);

        $methods = [
            ['label' => 'Refer a friend', 'points' => $p('referral'), 'how' => 'They sign up with your link and verify their email', 'once' => false],
            ['label' => 'Report a missing set', 'points' => $p('missing_set'), 'how' => 'A set you flag gets added to the catalog', 'once' => false],
            ['label' => 'Report a missing card', 'points' => $p('missing_card'), 'how' => 'A card you flag gets added to the catalog', 'once' => false],
            ['label' => 'Complete your profile', 'points' => $p('profile_complete'), 'how' => 'Set a username, avatar, and bio', 'once' => true],
            ['label' => 'Suggest a card edit', 'points' => $p('edit_suggestion'), 'how' => 'A correction you submit is accepted', 'once' => false],
            ['label' => '7-day check-in streak', 'points' => $p('streak_bonus'), 'how' => 'Check in 7 days in a row for a bonus', 'once' => false],
            ['label' => 'Run your first scan', 'points' => $p('first_scan'), 'how' => 'Scan any card', 'once' => true],
            ['label' => 'Add your first card', 'points' => $p('first_collection_card'), 'how' => 'Start your collection', 'once' => true],
            ['label' => 'Make a collection public', 'points' => $p('first_public_collection'), 'how' => 'Share a collection at your public URL', 'once' => true],
            ['label' => 'Rate a scan detection', 'points' => $p('scan_feedback'), 'how' => 'Confirm or correct a scan (helps train it)', 'once' => false],
            ['label' => 'Daily check-in', 'points' => $p('daily_checkin'), 'how' => 'Just visit each day', 'once' => false],
        ];

        usort($methods, fn ($a, $b) => $b['points'] <=> $a['points']);

        return $methods;
    }

    /** The open giveaway for this month, with prize + the viewer's entries. */
    private function currentGiveaway(?User $user): ?array
    {
        $giveaway = Giveaway::current();

        if (! $giveaway) {
            return null;
        }

        return [
            ...$giveaway->toCard(),
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
                'image' => $g->image_path,
                'winner' => $g->winner?->username ?? $g->winner?->name,
            ])
            ->all();
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
