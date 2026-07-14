<?php

namespace App\Http\Controllers\Web;

use App\Actions\Collection\BuildCollectionTree;
use App\Actions\Collection\BuildPortfolio;
use App\Http\Controllers\Controller;
use App\Models\CollectionItem;
use App\Models\User;
use App\Models\WishlistItem;
use App\Support\Membership\ScanQuota;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's home: portfolio snapshot, top movers, set allocation,
 * scan allowance, wishlist alerts, and recent additions. Read-only aggregation —
 * reuses the same Portfolio + Quota seams the collection/billing pages use.
 */
class DashboardController extends Controller
{
    public function index(Request $request, BuildPortfolio $build, BuildCollectionTree $tree): Response
    {
        $user = $request->user();

        // Whole-portfolio aggregation (all collections) — summary, allocation, movers.
        $portfolio = $build($user, null);

        return Inertia::render('dashboard', [
            'user' => [
                'name' => $user->name,
                'tier' => $user->membership_tier->value,
                'tier_label' => $user->isAdmin() ? 'Admin' : $user->membership_tier->label(),
                'is_admin' => $user->isAdmin(),
            ],
            'portfolio' => $portfolio['summary'],
            'movers' => [
                'gainers' => $portfolio['gainers'],
                'decliners' => $portfolio['decliners'],
            ],
            'allocation' => array_slice($portfolio['allocation'], 0, 6),
            'scans' => ScanQuota::for($user)->snapshot(),
            'community' => [
                'level' => $user->level(),
                'points' => (int) $user->contribution_points,
                'entries' => $user->monthlyEntries(),
            ],
            'wishlist' => $this->wishlistSummary($user),
            'portfolioHistory' => $this->portfolioHistory($user),
            'recentScans' => $this->recentScans($user),
            'recent' => $this->recentAdditions($portfolio['items']),
            // Collection → folder hierarchy with per-collection/per-folder value,
            // computed from the already-loaded portfolio items (no re-query).
            'collectionsTree' => $tree($user, $portfolio['items']),
            'counts' => [
                'collections' => $user->collections()->count(),
                'wishlists' => $user->wishlists()->count(),
            ],
        ]);
    }

    /**
     * The user's portfolio value over time from portfolio_snapshots. Points are
     * {t, price(cents)}; empty until there are ≥2 snapshot days.
     *
     * @return array<int, array{t: string, price: int}>
     */
    private function portfolioHistory(User $user): array
    {
        return $user->portfolioSnapshots()
            ->orderBy('captured_on')
            ->get(['captured_on', 'total_value_cents'])
            ->map(fn ($s) => [
                't' => $s->captured_on->toDateString(),
                'price' => (int) $s->total_value_cents,
            ])
            ->all();
    }

    /**
     * The user's most recent scans (with a thumbnail) for the dashboard card.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentScans(User $user): array
    {
        return $user->scanLogs()
            ->whereNotNull('image_path')
            ->take(4)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'mode' => $log->mode,
                'image_url' => $log->image_path,
                'card_count' => (int) $log->cards,
                'results' => array_slice($log->results ?? [], 0, 6),
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Wishlist totals across all of the user's lists, with at-or-below-target count. */
    private function wishlistSummary(User $user): array
    {
        $items = $user->wishlistItems()
            ->with('catalogItem.defaultMarketValue')
            ->get();

        return [
            'item_count' => $items->count(),
            'below_target' => $items->filter(function (WishlistItem $i) {
                $value = $i->currentValue();

                return $i->target_price !== null && $value !== null && $value <= $i->target_price;
            })->count(),
        ];
    }

    /**
     * The most recently added holdings (reuses the already-loaded portfolio items
     * so we don't re-query). Newest first.
     *
     * @param  Collection<int, CollectionItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function recentAdditions(Collection $items): array
    {
        return $items
            ->sortByDesc('created_at')
            ->take(6)
            ->map(function (CollectionItem $ci) {
                $unit = $ci->currentUnitValue();
                $catalog = $ci->catalogItem;

                return [
                    'id' => $ci->id,
                    'catalog_item_id' => $catalog?->id,
                    'url' => $catalog?->path(),
                    'name' => $catalog?->name,
                    'number' => $catalog?->number,
                    'set' => $catalog?->set?->name,
                    'image_url' => $catalog?->primary_image_path
                        ?? ($catalog?->external_ids['ptcgio_image'] ?? null),
                    'state' => $ci->stateLabel(),
                    'quantity' => $ci->quantity,
                    'market_value' => $unit !== null ? $unit * $ci->quantity : null,
                    'added_at' => $ci->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
