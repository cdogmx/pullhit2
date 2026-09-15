<?php

namespace App\Actions\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingPhoto;
use App\Support\Marketplace\ListingPhotoStore;
use Illuminate\Http\UploadedFile;

/**
 * Put a listing's photos in our own bucket and set their order.
 *
 * Stored, never hot-linked — the same rule the catalog images follow. A seller's
 * image host can change or vanish under us, and a listing whose photo 404s is
 * worse than one with no photo, because a buyer reads a broken image as a
 * broken seller.
 *
 * Order is the whole interface: the first photo is the browse tile, and for a
 * slab it should be the front with the cert legible, which is what makes the
 * cert-duplicate check worth anything.
 */
class SaveListingPhotos
{
    public function __construct(private ListingPhotoStore $photos) {}

    /**
     * @param  array<int, UploadedFile>  $uploads  new files, appended in order
     * @param  array<int, int>  $keepIds  existing photo ids to keep, in the order wanted
     * @return int photos on the listing afterwards
     */
    public function __invoke(MarketplaceListing $listing, array $uploads = [], ?array $keepIds = null): int
    {
        // Null means "leave what is there"; an empty array means "remove them
        // all". The difference matters: an edit that does not mention photos
        // must not silently delete them.
        if ($keepIds !== null) {
            $listing->photos()->whereNotIn('id', $keepIds ?: [0])->delete();

            foreach (array_values($keepIds) as $position => $id) {
                $listing->photos()->whereKey($id)->update(['sort_order' => $position]);
            }
        }

        $next = (int) $listing->photos()->max('sort_order');

        foreach ($uploads as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            // Re-encoded on the way in, which is what drops the GPS a phone
            // writes into every photo — a seller should not be publishing their
            // home address alongside a card worth four figures.
            $path = $this->photos->store($file);

            if ($path === null) {
                continue;
            }

            MarketplaceListingPhoto::create([
                'marketplace_listing_id' => $listing->id,
                'path' => $path,
                'sort_order' => ++$next,
            ]);
        }

        return $listing->photos()->count();
    }
}
