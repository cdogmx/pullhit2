<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Reconcile\ApplySet;
use App\Http\Controllers\Controller;
use App\Models\ReconciliationChange;
use App\Models\Set;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin review of the PriceCharting reconciliation queue — the low-confidence
 * changes (error/promo variants, missing cards, sealed product) the auto-apply
 * deliberately left for a human. Approve creates the item; skip dismisses it.
 */
class ReconcileController extends Controller
{
    public function index(): Response
    {
        $pending = ReconciliationChange::where('status', 'pending')
            ->with('set:id,name,slug')
            ->get();

        $sets = $pending->groupBy('set_id')
            ->map(function ($changes) {
                $set = $changes->first()->set;

                return [
                    'set_id' => $set?->id,
                    'set_name' => $set?->name ?? '—',
                    'total' => $changes->count(),
                    'counts' => $changes->groupBy('action')->map->count(),
                ];
            })
            ->sortByDesc('total')->values();

        return Inertia::render('admin/reconcile', [
            'sets' => $sets,
            'applied' => ReconciliationChange::where('status', 'applied')->count(),
            'pending' => $pending->count(),
        ]);
    }

    public function changes(Set $set): JsonResponse
    {
        $changes = ReconciliationChange::where('status', 'pending')
            ->where('set_id', $set->id)
            ->orderBy('action')->orderBy('id')
            ->get()
            ->map(fn (ReconciliationChange $c) => [
                'id' => $c->id,
                'action' => $c->action,
                'reason' => $c->reason,
                'label' => $c->payload['label'] ?? '',
                'attributes' => $c->payload['attributes'] ?? [],
                'ungraded' => $c->payload['prices']['ungraded'] ?? null,
                'psa10' => $c->payload['prices']['psa10'] ?? null,
            ]);

        return response()->json(['changes' => $changes]);
    }

    public function approve(ReconciliationChange $change, ApplySet $apply): RedirectResponse
    {
        $apply->applyStored($change);

        return back()->with('success', 'Applied.');
    }

    public function skip(ReconciliationChange $change, ApplySet $apply): RedirectResponse
    {
        $apply->skip($change);

        return back();
    }

    public function approveBatch(Request $request, ApplySet $apply): RedirectResponse
    {
        $data = $request->validate([
            'set_id' => ['required', 'integer'],
            'action' => ['required', 'string'],
        ]);

        ReconciliationChange::where('status', 'pending')
            ->where('set_id', $data['set_id'])
            ->where('action', $data['action'])
            ->get()
            ->each(fn (ReconciliationChange $c) => $apply->applyStored($c));

        return back()->with('success', 'Batch applied.');
    }
}
