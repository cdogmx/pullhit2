<?php

namespace App\Http\Controllers\Web;

use App\Actions\Marketplace\CompleteDeal;
use App\Actions\Marketplace\LeaveFeedback;
use App\Actions\Marketplace\LogDeal;
use App\Actions\Marketplace\OpenThread;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceThread;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Conversations, and the deals that come out of them.
 *
 * Every response here is scoped to the two people in the thread. There is no
 * admin view, no "similar conversations", and no way to reach a thread you are
 * not part of — people arrange payment in here, in prose, and that only works
 * if the room is genuinely private.
 */
class MarketplaceThreadController extends Controller
{
    /** The inbox. */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $threads = MarketplaceThread::query()
            ->for($user)
            ->with(['listing.photos', 'buyer:id,username', 'seller:id,username'])
            ->withCount('messages')
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->limit(100)
            ->get();

        return Inertia::render('marketplace/inbox', [
            'threads' => $threads->map(fn (MarketplaceThread $t) => [
                'id' => $t->id,
                'listing' => [
                    'title' => $t->listing?->title,
                    'photo' => $t->listing?->coverPhoto()?->path,
                    'price_cents' => $t->listing?->price_cents,
                ],
                'other' => $t->otherParty($user)?->username,
                'selling' => $t->seller_id === $user->id,
                'messages' => $t->messages_count,
                'unread' => $t->isUnreadBy($user),
                'last_message_at' => $t->last_message_at?->toIso8601String(),
                'url' => route('marketplace.threads.show', $t),
            ])->all(),
        ]);
    }

    /** Start (or return to) the conversation about a listing. */
    public function store(Request $request, MarketplaceListing $listing, OpenThread $open): RedirectResponse
    {
        try {
            $thread = $open($listing, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('marketplace.threads.show', $thread);
    }

    public function show(Request $request, MarketplaceThread $thread): Response
    {
        $user = $request->user();
        abort_unless($thread->includes($user), 403);

        $thread->load(['listing.photos', 'buyer:id,username', 'seller:id,username']);
        $thread->markReadBy($user);

        return Inertia::render('marketplace/thread', [
            'thread' => $this->threadPayload($thread, $user),
            'messages' => $this->messagePayload($thread),
            'deal' => $this->dealPayload($thread, $user),
        ]);
    }

    public function send(Request $request, MarketplaceThread $thread): RedirectResponse
    {
        $user = $request->user();
        abort_unless($thread->includes($user), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        MarketplaceMessage::create([
            'marketplace_thread_id' => $thread->id,
            'sender_id' => $user->id,
            'body' => trim($data['body']),
        ]);

        $thread->forceFill([
            'last_message_at' => now(),
            // The sender has obviously read their own message.
            $user->id === $thread->buyer_id ? 'buyer_read_at' : 'seller_read_at' => now(),
        ])->save();

        return back();
    }

    /**
     * New messages since an id, for the poll. Cheap on purpose: the thread page
     * asks every few seconds and most answers are empty.
     */
    public function poll(Request $request, MarketplaceThread $thread): JsonResponse
    {
        $user = $request->user();
        abort_unless($thread->includes($user), 403);

        $after = (int) $request->query('after', 0);

        $messages = $thread->messages()
            ->where('id', '>', $after)
            ->with('sender:id,username')
            ->limit(100)
            ->get();

        if ($messages->isNotEmpty()) {
            $thread->markReadBy($user);
        }

        return response()->json([
            'messages' => $messages->map($this->message(...))->values(),
            'deal' => $this->dealPayload($thread->fresh(), $user),
        ]);
    }

    public function proposeDeal(Request $request, MarketplaceThread $thread, LogDeal $deals): RedirectResponse
    {
        $user = $request->user();
        abort_unless($thread->includes($user), 403);

        $data = $request->validate([
            'price_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);

        try {
            $deals->propose($thread, $user, (int) $data['price_cents']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Deal logged — waiting for the other side to agree.');
    }

    public function actOnDeal(
        Request $request,
        MarketplaceDeal $deal,
        LogDeal $deals,
        CompleteDeal $complete,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($deal->includes($user), 403);

        $data = $request->validate([
            'action' => ['required', 'in:accept,confirm,cancel'],
        ]);

        try {
            match ($data['action']) {
                'accept' => $deals->accept($deal, $user),
                'confirm' => $deals->confirm($deal, $user, $complete),
                'cancel' => $deals->cancel($deal, $user),
            };
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function rate(Request $request, MarketplaceDeal $deal, LeaveFeedback $feedback): RedirectResponse
    {
        $user = $request->user();
        abort_unless($deal->includes($user), 403);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $feedback($deal, $user, (int) $data['rating'], $data['comment'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Thanks — feedback recorded.');
    }

    /** @return array<string, mixed> */
    private function threadPayload(MarketplaceThread $thread, User $user): array
    {
        $other = $thread->otherParty($user);

        return [
            'id' => $thread->id,
            'selling' => $thread->seller_id === $user->id,
            'other' => $other ? [
                'username' => $other->username,
                'direct_deals' => $other->direct_deal_count,
                'protected_deals' => $other->protected_deal_count,
                'rating' => $other->avg_rating !== null ? (float) $other->avg_rating : null,
            ] : null,
            'listing' => [
                'id' => $thread->listing?->id,
                'title' => $thread->listing?->title,
                'photo' => $thread->listing?->coverPhoto()?->path,
                'price_cents' => $thread->listing?->price_cents,
                'status' => $thread->listing?->status->value,
                'url' => $thread->listing ? route('marketplace.show', $thread->listing) : null,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function messagePayload(MarketplaceThread $thread): array
    {
        return $thread->messages()->with('sender:id,username')->limit(500)->get()
            ->map($this->message(...))->all();
    }

    /** @return array<string, mixed> */
    private function message(MarketplaceMessage $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'sender' => $message->sender?->username,
            'sender_id' => $message->sender_id,
            'sent_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /** The one open or most recent deal on this thread, as the page needs it. */
    private function dealPayload(MarketplaceThread $thread, User $user): ?array
    {
        $deal = $thread->deals()->open()->latest('id')->first()
            ?? $thread->deals()->latest('id')->first();

        if (! $deal) {
            return null;
        }

        return [
            'id' => $deal->id,
            'type' => $deal->type->value,
            'status' => $deal->status->value,
            'status_label' => $deal->status->label(),
            'price_cents' => $deal->agreed_price_cents,
            'awaiting_me' => $deal->awaitingAcceptanceFrom() === $user->id,
            'i_confirmed' => $deal->hasConfirmed($user),
            'they_confirmed' => $deal->hasConfirmed($thread->otherParty($user) ?? $user),
            'can_rate' => $deal->canBeRatedBy($user),
            'cancellable' => $deal->status->isCancellable(),
        ];
    }
}
