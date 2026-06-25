<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\NewFollower;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The community follow graph — follow/unfollow another collector and browse a
 * user's followers / following lists.
 */
class FollowController extends Controller
{
    public function store(Request $request, string $username): RedirectResponse
    {
        $target = User::where('username', $username)->firstOrFail();
        $me = $request->user();

        // No self-follows; syncWithoutDetaching keeps it idempotent. Only notify
        // on a genuinely new follow (attached is empty when already following).
        if ($me->id !== $target->id) {
            $result = $me->following()->syncWithoutDetaching([$target->id]);

            if (! empty($result['attached'])) {
                $target->notify(new NewFollower($me));
            }
        }

        return back();
    }

    public function destroy(Request $request, string $username): RedirectResponse
    {
        $target = User::where('username', $username)->firstOrFail();

        $request->user()->following()->detach($target->id);

        return back();
    }

    /** Render a user's followers or following list (type set via route default). */
    public function index(string $username, string $type): Response
    {
        $user = User::where('username', $username)->firstOrFail();

        $relation = $type === 'followers' ? $user->followers() : $user->following();

        $people = $relation
            ->whereNotNull('username')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'username' => $u->username,
                'avatar' => $u->avatar,
                'level' => $u->level()['name'],
            ]);

        return Inertia::render('profile/follows', [
            'username' => $user->username,
            'type' => $type,
            'people' => $people,
            'counts' => [
                'followers' => $user->followers()->count(),
                'following' => $user->following()->count(),
            ],
        ]);
    }
}
