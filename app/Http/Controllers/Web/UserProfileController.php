<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CollectionItem;
use App\Models\Contribution;
use App\Models\Giveaway;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public user profile at /u/{username} — the community face of an account:
 * avatar, handle, bio + links, contribution level/points/rank, badges &
 * giveaway wins, and (when public) a collection showcase. Same public-handle
 * privacy posture as the public collection pages (no real name, no email).
 */
class UserProfileController extends Controller
{
    public function show(Request $request, string $username): Response
    {
        $user = User::where('username', $username)->firstOrFail();

        $points = (int) $user->contribution_points;
        $rank = $points > 0
            ? User::where('contribution_points', '>', $points)->count() + 1
            : null;

        return Inertia::render('profile/show', [
            'meta' => $this->shareMeta($user, $points, $rank),
            'isOwner' => $request->user()?->id === $user->id,
            'profile' => [
                'username' => $user->username,
                'avatar' => $user->avatar,
                'bio' => $user->bio,
                'location' => $user->location,
                'website' => $user->website,
                'x_handle' => $user->x_handle,
                'instagram_handle' => $user->instagram_handle,
                'level' => $user->level(),
                'points' => $points,
                'rank' => $rank,
                'entries' => $user->monthlyEntries(),
                'contributions' => $user->contributions()->count(),
                'member_since' => $user->created_at?->toIso8601String(),
                'collection_url' => $user->is_collection_public ? "/collection/{$user->username}" : null,
                'wishlist_url' => $user->is_wishlist_public ? "/wishlist/{$user->username}" : null,
            ],
            'breakdown' => $this->breakdown($user),
            'wins' => $this->wins($user),
            'showcase' => $this->collectionShowcase($user),
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
     * Contribution counts + points grouped by type (edits, missing cards, sets).
     *
     * @return array<int, array<string, mixed>>
     */
    private function breakdown(User $user): array
    {
        return $user->contributions()
            ->selectRaw('type, count(*) as count, sum(points) as points')
            ->groupBy('type')
            ->get()
            ->map(fn (Contribution $c) => [
                'type' => $c->type->label(),
                'count' => (int) $c->count,
                'points' => (int) $c->points,
            ])
            ->all();
    }

    /**
     * Giveaways this user has won (drawn), newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function wins(User $user): array
    {
        return $user->wonGiveaways()
            ->where('status', Giveaway::DRAWN)
            ->orderByDesc('period')
            ->get()
            ->map(fn (Giveaway $g) => [
                'period_label' => $g->periodLabel(),
                'prize' => $g->prize,
                'image' => $g->image_path,
            ])
            ->all();
    }

    /**
     * A peek at the user's public default collection: total cards, total value,
     * and the most valuable cards as cover art. Null when not public or empty.
     *
     * @return array<string, mixed>|null
     */
    private function collectionShowcase(User $user): ?array
    {
        if (! $user->is_collection_public) {
            return null;
        }

        $collection = $user->collections()
            ->where('is_default', true)
            ->where('is_public', true)
            ->first();

        if (! $collection) {
            return null;
        }

        $items = $collection->items()
            ->with(['catalogItem.set', 'catalogItem.productLine', 'catalogItem.marketValues'])
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        $rows = $items->map(function (CollectionItem $ci) {
            $unit = $ci->currentUnitValue();

            return [
                'item' => $ci,
                'value' => $unit !== null ? $unit * $ci->quantity : 0,
            ];
        });

        $covers = $rows->sortByDesc('value')
            ->map(function (array $r) {
                $card = $r['item']->catalogItem;

                return [
                    'name' => $card?->display_name,
                    'image_url' => $card?->primary_image_path
                        ?? ($card?->external_ids['ptcgio_image'] ?? null),
                    'url' => $card?->path(),
                ];
            })
            ->filter(fn (array $c) => $c['image_url'] !== null)
            ->take(6)
            ->values()
            ->all();

        return [
            'url' => "/collection/{$user->username}",
            'card_count' => (int) $items->sum('quantity'),
            'total_value' => (int) $rows->sum('value'),
            'currency' => 'USD',
            'covers' => $covers,
        ];
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
        $description = $user->bio ?: trim(implode(' · ', array_filter([
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
