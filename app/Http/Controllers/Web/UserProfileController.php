<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public user profile at /u/{username} — the community face of an account:
 * avatar, handle, contribution level/points/rank, and links to their public
 * collection/wishlist. Reuses the same public-handle privacy posture as the
 * public collection pages (no real name, no email).
 */
class UserProfileController extends Controller
{
    public function show(string $username): Response
    {
        $user = User::where('username', $username)->firstOrFail();

        $points = (int) $user->contribution_points;
        $rank = $points > 0
            ? User::where('contribution_points', '>', $points)->count() + 1
            : null;

        return Inertia::render('profile/show', [
            'meta' => $this->shareMeta($user, $points, $rank),
            'profile' => [
                'username' => $user->username,
                'avatar' => $user->avatar,
                'level' => $user->level(),
                'points' => $points,
                'rank' => $rank,
                'entries' => $user->monthlyEntries(),
                'contributions' => $user->contributions()->count(),
                'member_since' => $user->created_at?->toIso8601String(),
                'collection_url' => $user->is_collection_public ? "/collection/{$user->username}" : null,
                'wishlist_url' => $user->is_wishlist_public ? "/wishlist/{$user->username}" : null,
            ],
            'recent' => $user->contributions()
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (Contribution $c) => [
                    'type' => $c->type->label(),
                    'points' => $c->points,
                    'description' => $c->description,
                    'at' => $c->created_at?->toIso8601String(),
                ]),
            'month' => now()->format('F Y'),
        ]);
    }

    /**
     * Server-rendered share/SEO meta so a shared /u/{handle} link previews with
     * the user's avatar + standing (social scrapers don't run JS).
     *
     * @return array<string, mixed>
     */
    private function shareMeta(User $user, int $points, ?int $rank): array
    {
        $level = $user->level()['name'] ?? 'Rookie';
        $description = trim(implode(' · ', array_filter([
            $level,
            $points > 0 ? number_format($points).' contribution points' : null,
            $rank ? "#{$rank} all-time" : null,
        ]))).' on CardFoo.';

        $meta = [
            'title' => "@{$user->username} on CardFoo",
            'description' => $description,
            'og_type' => 'profile',
        ];

        // A custom (square) avatar uses the small Twitter card; otherwise the
        // page falls back to the branded banner via the Blade defaults.
        if ($user->avatar) {
            $meta['image'] = $user->avatar;
            $meta['image_alt'] = "{$user->username}'s avatar";
            $meta['twitter_card'] = 'summary';
        }

        return $meta;
    }
}
