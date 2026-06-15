<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets a user opt in/out of non-critical email notifications. Account-critical
 * mail (email verification, password reset, billing receipts) is never gated.
 */
class NotificationController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/notifications', [
            // Each type with its copy and the user's current setting (default on).
            'types' => collect(User::NOTIFICATION_TYPES)
                ->map(fn (array $meta, string $key) => [
                    'key' => $key,
                    'label' => $meta['label'],
                    'description' => $meta['description'],
                    'enabled' => $user->wantsNotification($key),
                ])
                ->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferences' => ['array'],
            'preferences.*' => ['boolean'],
        ]);

        $user = $request->user();

        // Keep only known types; store the full explicit map (so "off" persists).
        $preferences = [];
        foreach (array_keys(User::NOTIFICATION_TYPES) as $key) {
            $preferences[$key] = (bool) ($validated['preferences'][$key] ?? false);
        }

        $user->notification_preferences = $preferences;
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings updated.')]);

        return to_route('notifications.edit');
    }
}
