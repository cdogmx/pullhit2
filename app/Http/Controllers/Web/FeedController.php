<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Following" feed — recent community activity (contributions) from the
 * people the signed-in user follows, newest first.
 */
class FeedController extends Controller
{
    public function index(Request $request): Response
    {
        $followingIds = $request->user()->following()->pluck('users.id');

        $paginator = Contribution::query()
            ->whereIn('user_id', $followingIds)
            ->whereHas('user', fn ($q) => $q->whereNotNull('username'))
            ->with('user:id,username,avatar_path')
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('feed/index', [
            'followingCount' => $followingIds->count(),
            'items' => collect($paginator->items())->map(fn (Contribution $c) => [
                'id' => $c->id,
                'user' => [
                    'username' => $c->user?->username,
                    'avatar' => $c->user?->avatar,
                ],
                'type' => $c->type->label(),
                'points' => (int) $c->points,
                'description' => $c->description,
                'at' => $c->created_at?->toIso8601String(),
            ]),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
