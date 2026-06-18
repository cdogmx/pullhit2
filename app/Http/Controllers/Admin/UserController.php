<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\CancelSubscription;
use App\Enums\MembershipTier;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin user management — search the roster and adjust an account: tier, admin
 * flag, scan credits, subscription, and bans. Mutations use forceFill since these
 * columns are deliberately guarded against mass assignment.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $f = [
            'q' => trim((string) $request->query('q', '')),
            'tier' => (string) $request->query('tier', ''),
            'role' => (string) $request->query('role', ''),
            'sort' => (string) $request->query('sort', 'recent'),
        ];

        $query = User::query()
            ->when($f['q'] !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$f['q']}%")
                ->orWhere('email', 'like', "%{$f['q']}%")
                ->orWhere('username', 'like', "%{$f['q']}%")))
            ->when($f['tier'] !== '', fn (Builder $q) => $q->where('membership_tier', $f['tier']))
            ->when($f['role'] === 'admin', fn (Builder $q) => $q->where('is_admin', true))
            ->when($f['role'] === 'banned', fn (Builder $q) => $q->whereNotNull('banned_at'));

        match ($f['sort']) {
            'name' => $query->orderBy('name'),
            'spend' => $query->orderByDesc('billing_transactions_sum_amount'),
            default => $query->orderByDesc('created_at'),
        };

        $paginator = $query
            ->withCount('billingTransactions')
            ->withSum(['billingTransactions as billing_transactions_sum_amount' => fn ($q) => $q->where('status', 'succeeded')], 'amount')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/users', [
            'users' => collect($paginator->items())->map(fn (User $u) => $this->row($u)),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $f,
            'tiers' => collect(MembershipTier::cases())->map(fn (MembershipTier $t) => [
                'value' => $t->value,
                'label' => $t->label(),
            ])->all(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'membership_tier' => ['sometimes', Rule::enum(MembershipTier::class)],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('membership_tier', $data)) {
            $user->forceFill(['membership_tier' => $data['membership_tier']]);
        }

        if (array_key_exists('is_admin', $data)) {
            // Guard against an admin stripping their own access by accident.
            if ($user->id === $request->user()->id && ! $data['is_admin']) {
                return back()->with('error', 'You cannot remove your own admin access.');
            }
            $user->forceFill(['is_admin' => $data['is_admin']]);
        }

        $user->save();

        return back()->with('success', 'User updated.');
    }

    public function credits(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'credits' => ['required', 'integer', 'min:-100000', 'max:100000'],
        ]);

        // Floor at zero so an over-large deduction can't go negative.
        $next = max(0, (int) $user->purchased_scan_credits + $data['credits']);
        $user->forceFill(['purchased_scan_credits' => $next])->save();

        return back()->with('success', 'Scan credits adjusted.');
    }

    public function cancel(User $user, CancelSubscription $cancel): RedirectResponse
    {
        return $cancel($user)
            ? back()->with('success', 'Subscription set to cancel at period end.')
            : back()->with('error', 'User has no active subscription to cancel.');
    }

    public function ban(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot ban yourself.');
        }

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $user->forceFill([
            'banned_at' => now(),
            'banned_reason' => $data['reason'] ?? null,
        ])->save();

        return back()->with('success', 'User banned.');
    }

    public function unban(User $user): RedirectResponse
    {
        $user->forceFill(['banned_at' => null, 'banned_reason' => null])->save();

        return back()->with('success', 'User reinstated.');
    }

    /** @return array<string, mixed> */
    protected function row(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'tier' => $user->membership_tier->value,
            'is_admin' => (bool) $user->is_admin,
            'banned_at' => $user->banned_at?->toIso8601String(),
            'banned_reason' => $user->banned_reason,
            'credits' => (int) $user->purchased_scan_credits,
            'has_subscription' => (bool) $user->dodo_subscription_id,
            'cancel_scheduled' => (bool) $user->membership_cancel_scheduled,
            'renews_at' => $user->membership_renews_at?->toIso8601String(),
            'transactions_count' => (int) ($user->billing_transactions_count ?? 0),
            'lifetime_amount' => (int) ($user->billing_transactions_sum_amount ?? 0),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
