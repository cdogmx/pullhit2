<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Billing\BuyCreditPack;
use App\Actions\Billing\CancelSubscription;
use App\Actions\Billing\StartCheckout;
use App\Http\Controllers\Controller;
use App\Support\Membership\ScanQuota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Subscriptions + scan-credit packs (Inertia settings page). Upgrades and credit
 * purchases redirect to Dodo's hosted checkout; the webhook applies the result.
 * Thin — delegates to the Billing Actions.
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
            'plans' => $this->plans(),
            'creditPacks' => $this->creditPacks(),
            'usage' => ScanQuota::for($user)->snapshot(),
        ]);
    }

    public function checkout(Request $request, StartCheckout $start): Response|RedirectResponse
    {
        $data = $request->validate(['tier' => ['required', Rule::in(['collector', 'guru'])]]);
        $user = $request->user();

        // Admins and users already on the chosen tier have nothing to buy.
        if ($user->isAdmin() || $user->membership_tier->value === $data['tier']) {
            return back();
        }

        return Inertia::location($start($user, $data['tier']));
    }

    public function buyCredits(Request $request, BuyCreditPack $buy): Response|RedirectResponse
    {
        $data = $request->validate([
            'pack' => ['required', Rule::in(array_keys((array) config('membership.credit_packs')))],
        ]);

        return Inertia::location($buy($request->user(), $data['pack']));
    }

    public function cancel(Request $request, CancelSubscription $cancel): RedirectResponse
    {
        $cancel($request->user());

        return back()->with('success', 'Your subscription will cancel at the end of the billing period.');
    }

    /** @return array<int, array<string, mixed>> */
    private function plans(): array
    {
        $caps = (array) config('membership.scan_caps');
        $limits = (array) config('membership.limits');
        $tiers = (array) config('membership.tiers');
        $fmt = fn (int $v): string => $v < 0 ? 'Unlimited' : number_format($v);

        return collect(['free', 'collector', 'guru'])->map(fn (string $key) => [
            'key' => $key,
            'name' => $tiers[$key]['name'] ?? ucfirst($key),
            'price_label' => $tiers[$key]['price_label'] ?? '',
            'scans' => (int) ($caps[$key] ?? 0),
            'collections' => $fmt((int) ($limits['collections'][$key] ?? 1)),
            'wishlists' => $fmt((int) ($limits['wishlists'][$key] ?? 1)),
            'alerts' => $fmt((int) ($limits['alerts'][$key] ?? 1)),
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function creditPacks(): array
    {
        return collect((array) config('membership.credit_packs'))
            ->map(fn ($pack, $key) => [
                'key' => $key,
                'credits' => (int) $pack['credits'],
                'price_label' => $pack['price_label'],
            ])
            ->values()
            ->all();
    }
}
