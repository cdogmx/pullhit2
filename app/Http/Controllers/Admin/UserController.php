<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\CancelSubscription;
use App\Enums\MembershipTier;
use App\Http\Controllers\Controller;
use App\Models\ScanLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    /**
     * A single account in depth: profile links, activity stats, the sessions
     * we've seen them from (IP + device), their scan history, and billing.
     */
    public function show(User $user): Response
    {
        $user->loadCount([
            'collectionItems', 'collections', 'wishlistItems', 'wishlists',
            'followers', 'following', 'scanLogs', 'contributions', 'cardReports',
        ]);

        // Sessions are stored in the DB (SESSION_DRIVER=database), giving us the
        // IPs and devices this account has signed in from.
        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->limit(15)
            ->get(['ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($s) => [
                'ip_address' => $s->ip_address,
                'user_agent' => $s->user_agent,
                'last_activity' => $s->last_activity
                    ? Carbon::createFromTimestamp($s->last_activity)->toIso8601String()
                    : null,
            ]);

        $scans = $user->scanLogs()->latest()->limit(20)->get()->map(fn (ScanLog $log) => [
            'id' => $log->id,
            'mode' => $log->mode,
            'image_url' => $log->image_path,
            'card_count' => (int) $log->cards,
            'ai_reads' => (int) $log->ai_reads,
            'cache_hits' => (int) $log->cache_hits,
            'results' => $log->results ?? [],
            'created_at' => $log->created_at?->toIso8601String(),
        ]);

        $transactions = $user->billingTransactions()->limit(15)->get()->map(fn ($t) => [
            'id' => $t->id,
            'type' => $t->type,
            'status' => $t->status,
            'description' => $t->description,
            'amount' => $t->amount,
            'currency' => $t->currency,
            'created_at' => $t->created_at?->toIso8601String(),
        ]);

        $level = $user->level();

        return Inertia::render('admin/users/show', [
            'user' => $this->row($user) + [
                'avatar' => $user->avatar,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'provider' => $user->provider,
                'last_seen_at' => $sessions->first()['last_activity'] ?? null,
            ],
            'links' => $user->username ? [
                'profile' => url('/u/'.$user->username),
                'collection' => url('/collection/'.$user->username),
                'wishlist' => url('/wishlist/'.$user->username),
            ] : null,
            'stats' => [
                'collection_items' => (int) $user->collection_items_count,
                'collections' => (int) $user->collections_count,
                'wishlist_items' => (int) $user->wishlist_items_count,
                'wishlists' => (int) $user->wishlists_count,
                'followers' => (int) $user->followers_count,
                'following' => (int) $user->following_count,
                'scans' => (int) $user->scan_logs_count,
                'contributions' => (int) $user->contributions_count,
                'card_reports' => (int) $user->card_reports_count,
                'contribution_points' => (int) $user->contribution_points,
                'monthly_entries' => $user->monthlyEntries(),
                'level' => $level['name'] ?? null,
            ],
            'sessions' => $sessions,
            'scans' => $scans,
            'transactions' => $transactions,
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
