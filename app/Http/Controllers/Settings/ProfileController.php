<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'username' => $user->username,
            'isCollectionPublic' => (bool) $user->is_collection_public,
            'publicUrl' => $user->username ? url("/collection/{$user->username}") : null,
        ]);
    }

    /**
     * Update the username + collection privacy (public/private).
     */
    public function updateCollection(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'username' => [
                'nullable', 'string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9_-]+$/',
                Rule::unique('users', 'username')->ignore($user->id),
                Rule::notIn(['export', 'import', 'settings', 'admin', 'api', 'new', 'edit']),
            ],
            'is_collection_public' => ['required', 'boolean'],
        ]);

        $username = $validated['username'] ?: null;

        if ($validated['is_collection_public'] && $username === null) {
            throw ValidationException::withMessages([
                'username' => 'Pick a username before making your collection public.',
            ]);
        }

        $user->fill([
            'username' => $username,
            'is_collection_public' => $validated['is_collection_public'],
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Collection settings updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
