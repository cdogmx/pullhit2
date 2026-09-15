<?php

namespace App\Http\Controllers\Web;

use App\Actions\Marketplace\SaveListingPhotos;
use App\Actions\Marketplace\SaveMarketplaceListing;
use App\Enums\Condition;
use App\Enums\ListingCategory;
use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Models\GradingCompany;
use App\Models\MarketplaceListing;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Catalog\LikeTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The marketplace: cards our users are selling to each other.
 *
 * Lives at /marketplace because /deals is already the retail deal tracker, and
 * two unrelated things called "deals" is how a codebase starts lying to you.
 *
 * CardFoo is a venue. Nothing in here moves money, quotes a fee, or records how
 * anyone paid — a buyer and seller settle that between themselves or through
 * Trustap, which is the merchant of record. That is a legal posture, not a
 * feature gap, and it is why there is no field to put a payment handle in.
 */
class MarketplaceController extends Controller
{
    /** Browse. */
    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'category' => $this->enumValue(ListingCategory::class, $request->query('category')),
            'product_line' => trim((string) $request->query('product_line', '')),
            'set' => trim((string) $request->query('set', '')),
            'grading_company' => trim((string) $request->query('grading_company', '')),
            'min_price' => $this->money($request->query('min_price')),
            'max_price' => $this->money($request->query('max_price')),
            'sort' => in_array($request->query('sort'), ['newest', 'price_asc', 'price_desc'], true)
                ? (string) $request->query('sort')
                : 'newest',
        ];

        $paginator = MarketplaceListing::query()
            ->visible()
            ->with(['photos', 'user:id,username', 'catalogItem:id,name,number,set_id', 'gradingCompany:id,slug,name'])
            ->when($filters['category'], fn (Builder $q, $c) => $q->where('category', $c))
            ->when($filters['grading_company'], fn (Builder $q, $slug) => $q
                ->whereHas('gradingCompany', fn (Builder $g) => $g->where('slug', $slug)))
            ->when($filters['product_line'], fn (Builder $q, $slug) => $q
                ->whereHas('catalogItem.productLine', fn (Builder $p) => $p->where('slug', $slug)))
            ->when($filters['set'], fn (Builder $q, $slug) => $q
                ->whereHas('catalogItem.set', fn (Builder $s) => $s->where('slug', $slug)))
            ->when($filters['min_price'], fn (Builder $q, $cents) => $q->where('price_cents', '>=', $cents))
            ->when($filters['max_price'], fn (Builder $q, $cents) => $q->where('price_cents', '<=', $cents))
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = LikeTerm::clean($filters['q']);

                $q->where(fn (Builder $w) => $w
                    ->where('title', 'like', "%{$term}%")
                    ->orWhere('set_code', 'like', "%{$term}%")
                    ->orWhere('card_number', 'like', "%{$term}%")
                    ->orWhereHas('catalogItem', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")));
            })
            ->when($filters['sort'] === 'price_asc', fn (Builder $q) => $q->orderBy('price_cents'))
            ->when($filters['sort'] === 'price_desc', fn (Builder $q) => $q->orderByDesc('price_cents'))
            ->when($filters['sort'] === 'newest', fn (Builder $q) => $q->ranked())
            ->paginate(24)
            ->withQueryString();

        return Inertia::render('marketplace/index', [
            'listings' => collect($paginator->items())->map($this->tile(...))->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'options' => $this->filterOptions($filters),
        ]);
    }

    public function show(MarketplaceListing $listing, Request $request): Response
    {
        abort_if(
            $listing->status === ListingStatus::Removed && ! $listing->isEditableBy($request->user()),
            404,
        );

        $listing->load(['photos', 'user:id,username,created_at', 'catalogItem.set', 'catalogItem.productLine', 'gradingCompany']);

        return Inertia::render('marketplace/show', [
            'listing' => $this->detail($listing),
            'canEdit' => $listing->isEditableBy($request->user()),
            // Other live listings offering the same cert. Two people cannot both
            // hold one slab, so this is the loudest scam signal the marketplace
            // can produce — shown to the buyer rather than buried in a report.
            'certConflicts' => $this->certConflicts($listing),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('marketplace/form', [
            'listing' => null,
            'options' => $this->formOptions(),
        ]);
    }

    public function edit(MarketplaceListing $listing, Request $request): Response
    {
        abort_unless($listing->isEditableBy($request->user()), 403);

        $listing->load(['photos', 'catalogItem.set']);

        return Inertia::render('marketplace/form', [
            'listing' => $this->detail($listing),
            'options' => $this->formOptions(),
        ]);
    }

    public function store(Request $request, SaveMarketplaceListing $save, SaveListingPhotos $photos): RedirectResponse
    {
        $data = $this->validated($request);

        $listing = $save($request->user(), $data);
        $photos($listing, $request->file('photos', []), null);

        // A listing with no photo is the single strongest scam signal a
        // marketplace has, so publishing is gated on one rather than trusted to
        // the form. Saved as a draft either way — the seller loses nothing.
        if ($listing->photos()->count() === 0 && $listing->status === ListingStatus::Active) {
            $listing->forceFill(['status' => ListingStatus::Draft])->save();

            return to_route('marketplace.edit', $listing)
                ->with('error', 'Add at least one photo before publishing. Saved as a draft.');
        }

        return to_route('marketplace.show', $listing)->with('success', 'Listing saved.');
    }

    public function update(
        Request $request,
        MarketplaceListing $listing,
        SaveMarketplaceListing $save,
        SaveListingPhotos $photos,
    ): RedirectResponse {
        abort_unless($listing->isEditableBy($request->user()), 403);

        $data = $this->validated($request);

        $save($request->user(), $data, $listing);
        $photos(
            $listing,
            $request->file('photos', []),
            // Absent means "leave the photos alone"; present means this is the
            // full set, in this order. An edit that never mentions photos must
            // not delete them.
            $request->has('keep_photo_ids') ? array_map('intval', (array) $request->input('keep_photo_ids', [])) : null,
        );

        if ($listing->fresh()->photos()->count() === 0 && $listing->fresh()->status === ListingStatus::Active) {
            $listing->forceFill(['status' => ListingStatus::Draft])->save();

            return back()->with('error', 'A published listing needs a photo. Moved back to draft.');
        }

        return to_route('marketplace.show', $listing)->with('success', 'Listing updated.');
    }

    /**
     * Sellers remove, they never delete: a listing is referenced by threads and
     * deals, and a sold card's history has to survive the seller tidying up.
     */
    public function destroy(Request $request, MarketplaceListing $listing): RedirectResponse
    {
        abort_unless($request->user()?->id === $listing->user_id, 403);

        $listing->forceFill(['status' => ListingStatus::Removed])->save();

        return to_route('marketplace.index')->with('success', 'Listing removed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', array_column(ListingCategory::cases(), 'value'))],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'catalog_item_id' => ['nullable', 'integer', 'exists:catalog_items,id'],
            'set_code' => ['nullable', 'string', 'max:32'],
            'card_number' => ['nullable', 'string', 'max:16'],
            'grading_company_id' => ['nullable', 'integer', 'exists:grading_companies,id'],
            'grade' => ['nullable', 'string', 'max:8'],
            'cert_number' => ['nullable', 'string', 'max:32'],
            'condition' => ['nullable', 'string', 'in:'.implode(',', array_column(Condition::cases(), 'value'))],
            // Priced in whole cents, and capped: a mistyped price is a support
            // ticket, and six figures is past what this venue should carry
            // without a conversation.
            'price_cents' => ['required', 'integer', 'min:1', 'max:100000000'],
            'accepts_offers' => ['boolean'],
            'accepts_direct' => ['boolean'],
            'accepts_escrow' => ['boolean'],
            'publish' => ['boolean'],
            'photos' => ['array', 'max:12'],
            'photos.*' => ['image', 'max:10240'],
            'keep_photo_ids' => ['array'],
            'keep_photo_ids.*' => ['integer'],
        ]);

        return $data;
    }

    /** @return array<string, mixed> */
    private function tile(MarketplaceListing $listing): array
    {
        return [
            'id' => $listing->id,
            'title' => $listing->title,
            'category' => $listing->category->value,
            'category_label' => $listing->category->label(),
            'price_cents' => $listing->price_cents,
            'currency' => $listing->currency,
            'accepts_offers' => $listing->accepts_offers,
            'accepts_escrow' => $listing->accepts_escrow,
            'photo' => $listing->coverPhoto()?->path,
            'grade' => $listing->grade,
            'grader' => $listing->gradingCompany?->slug,
            'condition' => $listing->condition?->value,
            'seller' => $listing->user?->username,
            'url' => route('marketplace.show', $listing),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(MarketplaceListing $listing): array
    {
        return $this->tile($listing) + [
            'description' => $listing->description,
            'set_code' => $listing->set_code,
            'card_number' => $listing->card_number,
            'cert_number' => $listing->cert_number,
            'accepts_direct' => $listing->accepts_direct,
            'status' => $listing->status->value,
            'status_label' => $listing->status->label(),
            'photos' => $listing->photos->map(fn ($p) => ['id' => $p->id, 'path' => $p->path])->all(),
            'grading_company_id' => $listing->grading_company_id,
            'catalog_item_id' => $listing->catalog_item_id,
            'card' => $listing->catalogItem ? [
                'name' => $listing->catalogItem->name,
                'number' => $listing->catalogItem->number,
                'set' => $listing->catalogItem->set?->name,
                'url' => $listing->catalogItem->path(),
            ] : null,
            'created_at' => $listing->created_at?->toIso8601String(),
            'expires_at' => $listing->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Live listings elsewhere claiming the same cert number.
     *
     * @return array<int, array<string, mixed>>
     */
    private function certConflicts(MarketplaceListing $listing): array
    {
        if (! $listing->cert_number) {
            return [];
        }

        return MarketplaceListing::query()
            ->visible()
            ->where('cert_number', $listing->cert_number)
            ->whereKeyNot($listing->id)
            ->with('user:id,username')
            ->limit(5)
            ->get()
            ->map(fn (MarketplaceListing $other) => [
                'id' => $other->id,
                'seller' => $other->user?->username,
                'url' => route('marketplace.show', $other),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function filterOptions(array $filters): array
    {
        return [
            'categories' => array_map(
                fn (ListingCategory $c) => ['value' => $c->value, 'label' => $c->label()],
                ListingCategory::cases(),
            ),
            'product_lines' => ProductLine::orderBy('name')->get(['slug', 'name']),
            'sets' => $filters['product_line']
                ? Set::whereHas('productLine', fn (Builder $p) => $p->where('slug', $filters['product_line']))
                    ->orderByDesc('released_at')->limit(300)->get(['slug', 'name'])
                : [],
            'grading_companies' => GradingCompany::orderBy('name')->get(['slug', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'categories' => array_map(
                fn (ListingCategory $c) => [
                    'value' => $c->value,
                    'label' => $c->label(),
                    'graded' => $c->isGraded(),
                    'has_condition' => $c->hasCondition(),
                ],
                ListingCategory::cases(),
            ),
            'conditions' => array_map(
                fn (Condition $c) => ['value' => $c->value, 'label' => $c->label()],
                array_filter(Condition::cases(), fn (Condition $c) => $c !== Condition::Sealed),
            ),
            'grading_companies' => GradingCompany::orderBy('name')->get(['id', 'slug', 'name']),
        ];
    }

    private function enumValue(string $enum, mixed $raw): ?string
    {
        $value = is_string($raw) ? $raw : null;

        return $value && $enum::tryFrom($value) ? $value : null;
    }

    private function money(mixed $raw): ?int
    {
        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }
}
