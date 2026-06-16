<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Billing\CancelSubscription;
use App\Actions\Billing\StartCheckout;
use App\Http\Controllers\Controller;
use App\Support\Membership\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Premium billing (Inertia settings page). Upgrade redirects to Dodo's hosted
 * checkout; the webhook flips the tier. Thin — delegates to the Billing Actions.
 */
class BillingController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/billing', [
            'tier' => $user->membership_tier->value,
            'isAdmin' => $user->isAdmin(),
            'renewsAt' => $user->membership_renews_at?->toIso8601String(),
            'cancelScheduled' => (bool) $user->membership_cancel_scheduled,
            'priceLabel' => config('membership.tiers.collector.price_label'),
            'premiumScanCap' => (int) config('membership.scan_caps.collector'),
        ]);
    }

    public function checkout(Request $request, StartCheckout $start): Response|RedirectResponse
    {
        // Already premium (or admin) — nothing to buy.
        if (Entitlements::for($request->user())->isPremium()) {
            return back();
        }

        $url = $start($request->user());

        // Full-page redirect to Dodo's hosted checkout.
        return Inertia::location($url);
    }

    public function cancel(Request $request, CancelSubscription $cancel): RedirectResponse
    {
        $cancel($request->user());

        return back()->with('success', 'Your subscription will cancel at the end of the billing period.');
    }
}
