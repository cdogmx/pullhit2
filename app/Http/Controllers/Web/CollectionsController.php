<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Support\Membership\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Manage a user's named collections (create / rename / share / delete). The
 * number of collections is gated by membership tier. Holdings live inside a
 * collection; deleting one moves its items back to the default collection.
 */
class CollectionsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $limit = Entitlements::for($user)->collectionLimit();
        if ($user->collections()->count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => "Your plan allows {$limit} collection(s). Upgrade for more.",
            ]);
        }

        $collection = $user->collections()->create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($user->id, $data['name']),
            'sort' => (int) $user->collections()->max('sort') + 1,
        ]);

        return back()->with('success', "Created “{$collection->name}”.");
    }

    public function update(Request $request, Collection $collection): RedirectResponse
    {
        abort_unless($collection->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'is_public' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data) && $data['name'] !== $collection->name) {
            $collection->name = $data['name'];
            $collection->slug = $this->uniqueSlug($collection->user_id, $data['name'], $collection->id);
        }

        if (array_key_exists('is_public', $data)) {
            $collection->is_public = $data['is_public'];
        }

        $collection->save();

        // Promoting a collection to default demotes the previous default.
        if (! empty($data['is_default']) && ! $collection->is_default) {
            $request->user()->collections()->where('id', '!=', $collection->id)->update(['is_default' => false]);
            $collection->update(['is_default' => true]);
        }

        return back()->with('success', 'Collection updated.');
    }

    public function destroy(Request $request, Collection $collection): RedirectResponse
    {
        abort_unless($collection->user_id === $request->user()->id, 403);

        if ($collection->is_default) {
            throw ValidationException::withMessages(['collection' => 'You can’t delete your default collection.']);
        }

        // Preserve holdings: move them back to the default collection.
        $default = $request->user()->defaultCollection();
        $collection->items()->update(['collection_id' => $default->id]);
        $collection->delete();

        return back()->with('success', 'Collection deleted; its cards moved to your default collection.');
    }

    /** A per-user-unique slug derived from the name (appends -2, -3, … on clash). */
    private function uniqueSlug(int $userId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'collection';
        $slug = $base;
        $n = 1;

        while (Collection::where('user_id', $userId)->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.++$n;
        }

        return $slug;
    }
}
