<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\RetailerLink;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public "deals" feed: products currently in stock at/below their target price
 * across the retailers we watch, plus a short history of recent alerts. Read-only
 * view over App\Models\RetailerLink state (no scraping happens here).
 */
class DealsController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('deals', [
            'deals' => $this->liveDeals(),
            'recent' => $this->recentAlerts(),
            'seo' => [
                'title' => 'In-stock deals — CardFoo',
                'heading' => 'In stock now',
            ],
        ]);
    }

    /** Products with at least one retailer currently in stock at/below target. */
    private function liveDeals(): array
    {
        $links = RetailerLink::query()
            ->where('is_active', true)
            ->where('last_qualified', true)
            ->whereHas('product', fn ($p) => $p->where('is_active', true))
            ->with('product.catalogItem')
            ->get();

        return $links
            ->groupBy('tracked_product_id')
            ->map(function (Collection $group) {
                $product = $group->first()->product;

                return [
                    'name' => $product->headline() ?? 'Product',
                    'image' => $product->preferredImage(),
                    'catalog_name' => $product->catalogItem?->name,
                    'currency' => $product->currency,
                    'target_price' => $product->target_price / 100,
                    'last_seen' => $group->max('last_checked_at')?->toIso8601String(),
                    'offers' => $group
                        ->sortBy('last_price')
                        ->map(fn (RetailerLink $l) => [
                            'retailer' => $l->retailer->label(),
                            'price' => $l->last_price === null ? null : $l->last_price / 100,
                            'url' => $l->url,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sortByDesc('last_seen')
            ->values()
            ->all();
    }

    /** Recent alerts that fired (tweeted) in the last 30 days. */
    private function recentAlerts(): array
    {
        return RetailerLink::query()
            ->whereNotNull('last_tweeted_at')
            ->where('last_tweeted_at', '>=', now()->subDays(30))
            ->with('product.catalogItem')
            ->orderByDesc('last_tweeted_at')
            ->take(30)
            ->get()
            ->map(fn (RetailerLink $l) => [
                'name' => $l->product->headline() ?? 'Product',
                'image' => $l->product->preferredImage(),
                'retailer' => $l->retailer->label(),
                'price' => $l->last_price === null ? null : $l->last_price / 100,
                'currency' => $l->product->currency,
                'url' => $l->url,
                'tweeted_at' => $l->last_tweeted_at->toIso8601String(),
            ])
            ->all();
    }
}
