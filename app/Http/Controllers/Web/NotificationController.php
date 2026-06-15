<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-app notification center — a persisted view of the notifications we also
 * email (wishlist alerts, edit reviews). Read-only list plus mark-as-read.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = $request->user()->notifications()->latest()->limit(50)->get();

        return Inertia::render('notifications/index', [
            'notifications' => $notifications->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->data['title'] ?? 'Notification',
                'body' => $n->data['body'] ?? '',
                'url' => $n->data['url'] ?? null,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at->diffForHumans(),
            ]),
        ]);
    }

    /** Mark every notification read, then return to the list. */
    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    /** Mark one read and follow it to its target (or back to the list). */
    public function show(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->first();

        if ($item === null) {
            return to_route('notifications.index');
        }

        $item->markAsRead();

        $url = $item->data['url'] ?? null;

        return $url ? redirect($url) : to_route('notifications.index');
    }
}
