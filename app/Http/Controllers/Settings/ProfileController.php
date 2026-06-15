<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    use ProfileValidationRules;

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
            'isWishlistPublic' => (bool) $user->is_wishlist_public,
            'publicUrl' => $user->username ? url("/collection/{$user->username}") : null,
            'wishlistPublicUrl' => $user->username ? url("/wishlist/{$user->username}") : null,
        ]);
    }

    /**
     * Update collection/wishlist privacy. The username is chosen once (at signup)
     * and is permanent — it's only settable here for legacy accounts that don't
     * have one yet, never changeable once set.
     */
    public function updateCollection(Request $request): RedirectResponse
    {
        $user = $request->user();

        $rules = [
            'is_collection_public' => ['required', 'boolean'],
            'is_wishlist_public' => ['required', 'boolean'],
        ];

        if ($user->username === null) {
            $rules['username'] = $this->usernameRules($user->id);
        }

        $validated = $request->validate($rules);

        if ($user->username === null && isset($validated['username'])) {
            $user->username = $validated['username'];
        }

        $user->is_collection_public = $validated['is_collection_public'];
        $user->is_wishlist_public = $validated['is_wishlist_public'];
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sharing settings updated.')]);

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
