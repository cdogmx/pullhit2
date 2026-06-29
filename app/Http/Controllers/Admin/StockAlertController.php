<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Alerts\CheckStockAlerts;
use App\Http\Controllers\Controller;
use App\Models\StockAlert;
use App\Support\Social\XClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin watch list for Amazon stock alerts. Add an ASIN + target price; the
 * scheduled checker (stock:check-alerts) polls via Oxylabs and tweets when it's
 * in stock at/below target. See App\Actions\Alerts\CheckStockAlerts.
 */
class StockAlertController extends Controller
{
    public function index(XClient $x): Response
    {
        return Inertia::render('admin/stock-alerts', [
            'alerts' => StockAlert::query()->latest()->get()->map(fn (StockAlert $a) => $this->present($a)),
            'xConfigured' => $x->configured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateAlert($request);

        StockAlert::create([
            ...$data,
            'target_price' => (int) round((float) $data['target_price'] * 100),
        ]);

        return back()->with('success', 'Alert added.');
    }

    public function update(Request $request, StockAlert $stockAlert): RedirectResponse
    {
        $data = $this->validateAlert($request);

        $stockAlert->update([
            ...$data,
            'target_price' => (int) round((float) $data['target_price'] * 100),
        ]);

        return back()->with('success', 'Alert updated.');
    }

    public function toggle(StockAlert $stockAlert): RedirectResponse
    {
        $stockAlert->update(['is_active' => ! $stockAlert->is_active]);

        return back()->with('success', $stockAlert->is_active ? 'Alert resumed.' : 'Alert paused.');
    }

    public function destroy(StockAlert $stockAlert): RedirectResponse
    {
        $stockAlert->delete();

        return back()->with('success', 'Alert deleted.');
    }

    /** Run a single alert now (ignores throttle); --dry unless ?tweet=1. */
    public function check(Request $request, StockAlert $stockAlert, CheckStockAlerts $action): RedirectResponse
    {
        $result = $action->evaluate($stockAlert, dryRun: ! $request->boolean('tweet'));

        $msg = $result['error']
            ? 'Check failed — see status.'
            : ($result['qualified'] ? 'Checked: in stock at/below target.' : 'Checked: not qualifying right now.');

        return back()->with($result['error'] ? 'error' : 'success', $msg);
    }

    /** @return array<string, mixed> */
    private function validateAlert(Request $request): array
    {
        return $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'asin' => ['required', 'string', 'regex:/^[A-Z0-9]{10}$/'],
            'domain' => ['required', 'string', 'max:8'],
            'geo_location' => ['nullable', 'string', 'max:120'],
            'target_price' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'currency' => ['required', Rule::in(['USD', 'GBP', 'EUR', 'CAD', 'JPY'])],
            'check_interval_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'is_active' => ['boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(StockAlert $a): array
    {
        return [
            'id' => $a->id,
            'label' => $a->label,
            'asin' => $a->asin,
            'domain' => $a->domain,
            'geo_location' => $a->geo_location,
            'target_price' => $a->target_price / 100,
            'currency' => $a->currency,
            'check_interval_minutes' => $a->check_interval_minutes,
            'is_active' => $a->is_active,
            'url' => $a->productUrl(),
            'last_checked_at' => $a->last_checked_at?->toIso8601String(),
            'last_price' => $a->last_price === null ? null : $a->last_price / 100,
            'last_in_stock' => $a->last_in_stock,
            'last_status' => $a->last_status,
            'last_title' => $a->last_title,
            'last_qualified' => $a->last_qualified,
            'last_error' => $a->last_error,
            'last_tweeted_at' => $a->last_tweeted_at?->toIso8601String(),
        ];
    }
}
