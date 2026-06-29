<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\RetailerLink;
use App\Models\TrackedProduct;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public "deals" feed. Products in stock at/below their target (MSRP) are deals;
 * products in stock only above target are shown in a separate "above MSRP" area
 * so visitors can still see availability. Plus a short history of recent alerts.
 * Read-only over App\Models\RetailerLink state (no scraping happens here).
 */
class DealsController extends Controller
{
    public function __invoke(): Response
    {
        ['deals' => $deals, 'aboveMsrp' => $aboveMsrp] = $this->inStock();

        return Inertia::render('deals', [
            'deals' => $deals,
            'aboveMsrp' => $aboveMsrp,
            'recent' => $this->recentAlerts(),
            'seo' => [
                'title' => 'In-stock deals — CardFoo',
                'heading' => 'In stock now',
            ],
        ]);
    }

    /**
     * Split in-stock products into at/below-target deals and above-MSRP listings.
     *
     * @return array{deals: array<int, mixed>, aboveMsrp: array<int, mixed>}
     */
    private function inStock(): array
    {
        $links = RetailerLink::query()
            ->where('is_active', true)
            ->where('last_in_stock', true)
            ->whereNotNull('last_price')
            ->whereHas('product', fn ($p) => $p->where('is_active', true))
            ->with(['product.catalogItem.productLine', 'product.catalogItem.set'])
            ->get();

        $deals = [];
        $aboveMsrp = [];

        foreach ($links->groupBy('tracked_product_id') as $group) {
            $product = $group->first()->product;
            $atOrBelow = $group->filter(fn (RetailerLink $l) => $l->last_price <= $product->target_price);

            if ($atOrBelow->isNotEmpty()) {
                $deals[] = $this->presentProduct($product, $atOrBelow);
            } else {
                $aboveMsrp[] = $this->presentProduct($product, $group, overMsrp: true);
            }
        }

        usort($deals, fn ($a, $b) => ($b['last_seen'] ?? '') <=> ($a['last_seen'] ?? ''));
        // Closest to MSRP first — most likely to come down to target.
        usort($aboveMsrp, fn ($a, $b) => $a['offers'][0]['over_pct'] <=> $b['offers'][0]['over_pct']);

        return ['deals' => $deals, 'aboveMsrp' => $aboveMsrp];
    }

    /**
     * @param  Collection<int, RetailerLink>  $links
     * @return array<string, mixed>
     */
    private function presentProduct(TrackedProduct $product, Collection $links, bool $overMsrp = false): array
    {
        $target = $product->target_price;
        $item = $product->catalogItem;

        $offers = $links
            ->sortBy('last_price')
            ->map(fn (RetailerLink $l) => [
                'retailer' => $l->retailer->label(),
                'price' => $l->last_price / 100,
                'url' => $l->url,
                // How far over target (MSRP), for the above-MSRP view.
                'over_pct' => $target > 0 ? (int) round((($l->last_price - $target) / $target) * 100) : 0,
            ])
            ->values()
            ->all();

        return [
            'name' => $product->headline() ?? 'Product',
            'image' => $product->preferredImage(),
            'catalog_name' => $product->catalogItem?->name,
            // Brand/series are derived from the attached catalog item (if any),
            // and power the Deals page filters.
            'brand' => $item?->productLine?->name,
            'brand_slug' => $item?->productLine?->slug,
            'series' => $item?->set?->series,
            'currency' => $product->currency,
            'target_price' => $target / 100,
            'over_msrp' => $overMsrp,
            'last_seen' => $links->max('last_checked_at')?->toIso8601String(),
            'offers' => $offers,
        ];
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
