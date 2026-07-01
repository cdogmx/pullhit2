<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Community\AwardPoints;
use App\Concerns\ProfileValidationRules;
use App\Enums\ContributionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Support\Catalog\CardImageStore;
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

        // Keep the default collection/wishlist public flags in sync (they drive
        // the /collection/{username} and /wishlist/{username} public pages).
        $user->defaultCollection()->update(['is_public' => $user->is_collection_public]);
        $user->defaultWishlist()->update(['is_public' => $user->is_wishlist_public]);

        if ($user->is_collection_public) {
            app(AwardPoints::class)($user, ContributionType::FirstPublicCollection, description: 'Made a collection public');
        }
        $this->maybeAwardProfileComplete($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sharing settings updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Upload (or replace) the user's avatar. Stored as our own S3 copy.
     */
    public function updateAvatar(Request $request, CardImageStore $images): RedirectResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'max:5120']]);

        $url = $images->storeUpload($request->file('avatar'));
        abort_if($url === null, 422, 'Could not store that image.');

        $request->user()->forceFill(['avatar_path' => $url])->save();
        $this->maybeAwardProfileComplete($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Avatar updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Remove the user's avatar (revert to initials).
     */
    public function deleteAvatar(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['avatar_path' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Avatar removed.')]);

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
        $this->maybeAwardProfileComplete($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Award the one-time "completed profile" points once a user has a username,
     * an avatar, and a bio. Idempotent (once ever per user).
     */
    private function maybeAwardProfileComplete(User $user): void
    {
        if ($user->username && $user->avatar_path && filled($user->bio)) {
            app(AwardPoints::class)($user, ContributionType::ProfileComplete, description: 'Completed profile');
        }
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
