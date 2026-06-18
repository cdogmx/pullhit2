<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin billing ledger — every recorded Dodo money movement across all users,
 * with running totals. Read-only; the webhook is the only writer.
 */
class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $f = [
            'q' => trim((string) $request->query('q', '')),
            'type' => (string) $request->query('type', ''),
            'status' => (string) $request->query('status', ''),
        ];

        $query = BillingTransaction::query()
            ->with('user:id,name,email,username')
            ->when($f['type'] !== '', fn (Builder $q) => $q->where('type', $f['type']))
            ->when($f['status'] !== '', fn (Builder $q) => $q->where('status', $f['status']))
            ->when($f['q'] !== '', fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w
                    ->where('dodo_payment_id', 'like', "%{$f['q']}%")
                    ->orWhere('dodo_subscription_id', 'like', "%{$f['q']}%")
                    ->orWhereHas('user', fn (Builder $u) => $u
                        ->where('email', 'like', "%{$f['q']}%")
                        ->orWhere('name', 'like', "%{$f['q']}%"))));

        $paginator = $query->latest()->paginate(40)->withQueryString();

        return Inertia::render('admin/transactions', [
            'transactions' => collect($paginator->items())->map(fn (BillingTransaction $t) => $this->row($t)),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $f,
            'totals' => $this->totals(),
        ]);
    }

    /** Gross succeeded revenue and refund totals (all time), in cents. */
    protected function totals(): array
    {
        return [
            'gross' => (int) BillingTransaction::where('status', 'succeeded')->sum('amount'),
            'refunded' => (int) BillingTransaction::where('status', 'refunded')->sum('amount'),
            'count' => (int) BillingTransaction::count(),
        ];
    }

    /** @return array<string, mixed> */
    protected function row(BillingTransaction $t): array
    {
        return [
            'id' => $t->id,
            'type' => $t->type,
            'status' => $t->status,
            'description' => $t->description,
            'amount' => $t->amount,
            'currency' => $t->currency,
            'tier' => $t->tier,
            'credits' => $t->credits,
            'dodo_payment_id' => $t->dodo_payment_id,
            'created_at' => $t->created_at?->toIso8601String(),
            'user' => $t->user ? [
                'id' => $t->user->id,
                'name' => $t->user->name,
                'email' => $t->user->email,
            ] : null,
        ];
    }
}
