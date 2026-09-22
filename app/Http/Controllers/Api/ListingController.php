<?php

namespace App\Http\Controllers\Api;

use App\Catalog\Brands;
use App\Catalog\Facets;
use App\Catalog\ListingFilter;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Category;
use App\Enums\OrderStatus;
use App\Models\Listing;
use App\Models\Message;
use App\Search\ListingSearch;
use App\Services\PriceInsightService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ListingController extends Controller
{
    private const CONDITIONS = ['MINT', 'EXCELLENT', 'GOOD', 'FAIR'];

    public function __construct(private readonly PriceInsightService $priceInsight) {}

    /**
     * Browse and search, with the filter counts the panel is drawn from.
     *
     * The division of labour is the thing to understand here. Elasticsearch
     * answers one question, "which listings match these words", and answers
     * it with a list of ids. Everything else - the category tree, the brands,
     * the per-category attributes, the counts beside each of them - is MySQL,
     * because the counts and the results have to agree with each other and
     * keeping two filter implementations in step is a promise nobody keeps.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'brands' => ['nullable', 'array', 'max:50'],
            'brands.*' => ['string', 'max:80'],
            'conditions' => ['nullable', 'array', 'max:4'],
            'conditions.*' => ['string', Rule::in(self::CONDITIONS)],
            'locations' => ['nullable', 'array', 'max:50'],
            'locations.*' => ['string', 'max:120'],
            'attributes' => ['nullable', 'array', 'max:40'],
            'attributes.*' => ['array', 'max:40'],
            'attributes.*.*' => ['string', 'max:120'],
            'availability' => ['nullable', Rule::in(['all', 'available'])],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc', 'relevance'])],
        ]);

        $category = isset($data['category'])
            ? Category::where('slug', $data['category'])->firstOrFail()
            : null;

        // Attribute names that are not real facets are dropped rather than
        // rejected. A stale bookmark from before a filter was renamed should
        // show the buyer some listings, not a validation error.
        $data['attributes'] = array_intersect_key(
            $data['attributes'] ?? [],
            array_flip(Facets::names()),
        );

        $term = trim((string) ($data['search'] ?? ''));
        $searchIds = $term === '' ? null : $this->searchIds($term);

        $filter = new ListingFilter(
            input: $data,
            category: $category,
            searchIds: $searchIds,
            // Used only when the cluster did not answer, so the SQL LIKE
            // fallback still finds something.
            searchTerm: $searchIds === null ? $term : null,
        );

        // index has no auth:sanctum middleware (it's public), so nothing has
        // told the auth manager which guard to use - plain user() would
        // silently always be null here even with a valid Bearer token.
        // Naming the guard explicitly resolves it regardless.
        $viewerId = $request->user('sanctum')?->id;

        $listings = $filter->results((int) $request->input('page', 1), $viewerId);

        return ListingResource::collection($listings)->additional([
            'facets' => $filter->facets(),
            'category' => $category === null ? null : $this->categoryContext($category),
        ]);
    }

    /**
     * The ids matching a text query, or null when the cluster cannot answer.
     *
     * Null and an empty array mean different things and both are real: null
     * is "search is off or broken, fall back to SQL", while an empty array is
     * "the cluster looked and there is nothing". Collapsing the two would
     * turn every no-results search into a full listing of the site.
     *
     * @return int[]|null
     */
    private function searchIds(string $term): ?array
    {
        if (! config('elasticsearch.enabled')) {
            return null;
        }

        try {
            return app(ListingSearch::class)->matchingIds($term, ListingFilter::SEARCH_ID_CAP);
        } catch (\Throwable $e) {
            // Browsing has to keep working when the cluster does not, so this
            // is logged for whoever is on call and then forgotten about here.
            Log::warning('Listing search failed, falling back to the database', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Where the buyer is in the tree, and what they can filter by there.
     *
     * Sent alongside the results rather than fetched separately, because the
     * filter panel and the listings it filters should never be able to
     * disagree about which category is being viewed.
     *
     * @return array<string, mixed>
     */
    private function categoryContext(Category $category): array
    {
        $ancestors = Category::whereIn('path', $category->pathSegments())
            ->orderBy('depth')
            ->get(['slug', 'path', 'name', 'depth']);

        return [
            'slug' => $category->slug,
            'path' => $category->path,
            'name' => $category->name,
            'depth' => $category->depth,
            'is_leaf' => $category->is_leaf,
            'breadcrumbs' => $ancestors->map(fn (Category $c) => [
                'slug' => $c->slug,
                'name' => $c->name,
            ])->all(),
            // Definitions, not counts. The counts come back under `facets`,
            // and the panel needs both: the labels and option order from
            // here, and how many listings are behind each option from there.
            'facet_definitions' => collect($category->facets())
                ->map(fn (array $definition, string $name) => [
                    'name' => $name,
                    'label' => $definition['label'],
                    'options' => $definition['options'],
                    'help' => $definition['help'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The signed-in user's saved listings. Registered BEFORE the {listing}
     * show route - Laravel matches routes in registration order, and without
     * that ordering "saved" would be swallowed by {listing} as if it were an id.
     */
    public function saved(Request $request)
    {
        $listings = $request->user()->savedListings()
            ->with('seller', 'media', 'category')
            ->latest('saved_listings.created_at')
            ->paginate(20);

        // Every row here is, by definition, saved by the viewer - set the
        // flag directly rather than re-querying the pivot a second time.
        $listings->getCollection()->each(
            fn ($listing) => $listing->setRelation('savedBy', collect([true]))
        );

        return ListingResource::collection($listings);
    }

    /**
     * Listings similar to this one, for the "you might also like" rail.
     *
     * Its own endpoint rather than part of show(): recommendations are the
     * least important thing on the page, and the listing itself should not
     * wait on a second search round trip, nor fail to render if that round
     * trip fails.
     *
     * There is a SQL fallback now, which there deliberately was not before.
     * When a category was one of five words, "other things in the same
     * category" meant "other guitars" and was worse than showing nothing.
     * Against a tree this deep it means "other Telecaster-shaped solid body
     * electrics", which is a genuine recommendation.
     */
    public function similar(Request $request, Listing $listing)
    {
        $viewerId = $request->user('sanctum')?->id;
        $listing->loadMissing('category');

        if ($listing->category === null) {
            return ListingResource::collection(collect());
        }

        $ids = $this->similarIds($listing);

        if ($ids === null) {
            return ListingResource::collection($this->similarFromDatabase($listing, $viewerId));
        }

        return ListingResource::collection($this->hydrate($ids, $viewerId));
    }

    /** @return int[]|null null when the cluster cannot answer */
    private function similarIds(Listing $listing): ?array
    {
        if (! config('elasticsearch.enabled')) {
            return null;
        }

        try {
            // Siblings rather than the exact leaf: a buyer looking at a 12"
            // reissue is quite likely to want a different pressing of it, and
            // restricting to the leaf would hide every one that happens to be
            // filed a rung away.
            $scope = $listing->category->parent?->path ?? $listing->category->path;

            return app(ListingSearch::class)->similarTo($listing->id, $scope);
        } catch (\Throwable $e) {
            Log::warning('Similar listings lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Nearest first: the same leaf category, then the rest of the branch,
     * with the closest price acting as the tiebreak inside each.
     */
    private function similarFromDatabase(Listing $listing, ?int $viewerId)
    {
        $branch = $listing->category->parent?->path ?? $listing->category->path;

        $siblingIds = Category::withinPath($branch)->pluck('id');

        return Listing::query()
            ->whereIn('category_id', $siblingIds)
            ->where('id', '!=', $listing->id)
            ->where('status', 'ACTIVE')
            ->with('seller', 'media', 'category')
            ->when($viewerId, fn ($q) => $q->with(['savedBy' => fn ($s) => $s->where('users.id', $viewerId)]))
            ->orderByRaw('category_id = ? desc', [$listing->category_id])
            ->orderByRaw('ABS(price - ?) asc', [(float) $listing->price])
            ->limit(6)
            ->get();
    }

    /**
     * Loads the matched listings in the order the search ranked them.
     *
     * One query, not one per id, and the ordering is reapplied in PHP: SQL
     * has no memory of the relevance ordering, and doing it with a generated
     * FIELD() clause would tie this to MySQL for no real gain.
     */
    private function hydrate(array $ids, ?int $viewerId)
    {
        if ($ids === []) {
            return collect();
        }

        $listings = Listing::query()
            ->whereIn('id', $ids)
            ->with('seller', 'media', 'category')
            ->when($viewerId, fn ($q) => $q->with(['savedBy' => fn ($q) => $q->where('users.id', $viewerId)]))
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn ($id) => $listings->get($id))
            // A listing deleted between being indexed and being read is a
            // gap in the results, not a null in the JSON.
            ->filter()
            ->values();
    }

    public function show(Request $request, Listing $listing)
    {
        $listing->load('seller', 'media', 'category', 'attributeValues');

        // Same reason as index() above - show has no auth:sanctum middleware.
        if ($viewerId = $request->user('sanctum')?->id) {
            $listing->load(['savedBy' => fn ($q) => $q->where('users.id', $viewerId)]);
        }

        return (new ListingResource($listing))->additional([
            'price_insight' => $this->priceInsight->forListing($listing),

            // A price this viewer has already been promised, so the buy
            // button can offer it by name. It belongs here rather than on
            // ListingResource because it is one query per listing: harmless
            // on a page showing one, an N+1 the moment the same resource is
            // used for a grid of forty.
            'your_offer' => $viewerId ? $this->claimableOfferFor($viewerId, $listing) : null,
            // So the listing page can label each stored attribute without
            // knowing the taxonomy itself.
            'attribute_labels' => collect($listing->category?->facets() ?? [])
                ->map(fn (array $definition) => $definition['label'])
                ->all(),
        ]);
    }

    /**
     * What this viewer would actually be charged for the item, if they have
     * talked the seller down and the agreement is still standing.
     *
     * Deliberately a description rather than a decision: CheckoutService
     * runs the same lookup again under a row lock when the money is
     * involved, because anything read here is read outside a transaction and
     * could be stale by the time a card is entered. This one only decides
     * what a button says.
     *
     * @return array{amount: string, expires_at: string}|null
     */
    private function claimableOfferFor(int $viewerId, Listing $listing): ?array
    {
        $offer = Message::claimableBy($viewerId, $listing->id)->first();

        return $offer === null ? null : [
            'amount' => (string) $offer->offer_amount,
            'expires_at' => $offer->offer_expires_at->toJSON(),
        ];
    }

    // Buying lives in CheckoutController now. What used to be here marked a
    // listing SOLD and moved no money, which was honest about being a demo
    // and dishonest about being a marketplace. The row-locking it pioneered
    // survives in CheckoutService::reserve, and the lock ordering note there
    // is the one the offer-accept path in MessageController depends on.

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $category = $this->resolveCategory($data['category']);
        $attributes = $this->validatedAttributes($request, $category);

        // 'condition' is fillable, so merging a default in here before make()
        // works via normal mass assignment.
        $data['condition'] ??= 'GOOD';
        $data['category_id'] = $category->id;
        unset($data['category']);

        $listing = $request->user()->listings()->make($data);
        // 'status' is deliberately NOT in Listing's #[Fillable] list - request
        // input must never set it directly. Passing it through make()/create()
        // like 'condition' above would silently be dropped by that guard, not
        // set (found by testing the actual response, not by reading the code -
        // it looked right, it wasn't). Direct property assignment bypasses
        // mass-assignment protection, which is correct here since this value
        // is a hardcoded server-side constant, not user input.
        $listing->status = 'ACTIVE';
        $listing->save();

        $listing->syncAttributes($attributes);

        // 'media' is loaded even though a brand-new listing has none yet:
        // ListingResource only emits that key when the relation is loaded, so
        // leaving it off makes the field silently ABSENT rather than an empty
        // array, and callers then have to handle two shapes for one resource.
        return new ListingResource($listing->load('seller', 'media', 'category', 'attributeValues'));
    }

    /**
     * Take a listing down.
     *
     * Sellers sell things elsewhere, change their minds, and post the wrong
     * thing, and until now had no way to undo any of it: the only delete
     * route on the whole API was for individual media files. A listing that
     * cannot be withdrawn is one the seller has to email someone about.
     *
     * A listing with a sale behind it is NOT deletable, by anyone. The
     * orders table takes listing_id with cascadeOnDelete, so removing the
     * row would take the order with it - the buyer's purchase history and
     * the record behind a real card payment - and it would go quietly. The
     * check below is what stands between a seller tidying up and an audit
     * trail disappearing.
     *
     * Cancelled orders do not count: a checkout somebody abandoned is not a
     * sale, and leaving those blocking deletion would mean one lapsed
     * reservation froze a listing permanently.
     */
    public function destroy(Request $request, Listing $listing)
    {
        $this->authorize('delete', $listing);

        $liveOrders = $listing->orders()
            ->where('status', '!=', OrderStatus::CANCELLED->value)
            ->exists();

        if ($liveOrders) {
            throw ValidationException::withMessages([
                'listing' => 'This listing has a sale against it and cannot be deleted. '
                    .'Its order history has to stay put.',
            ]);
        }

        // Files first. Doing it the other way round means a failure here
        // leaves media on disk with nothing in the database pointing at it,
        // which nothing will ever clean up - the exact leak the per-listing
        // upload cap exists to bound.
        $disk = Storage::disk(config('media.disk'));

        foreach ($listing->media as $media) {
            $disk->delete($media->path);
        }

        $listing->delete();

        return response()->json(['message' => 'Listing deleted.']);
    }

    public function update(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $data = $this->validated($request, forUpdate: true);

        // The category can move, and when it does the old category's
        // attributes go with it. A body shape left over from when this was a
        // guitar is meaningless once it is filed under cables, and leaving it
        // there would put it in a filter it does not belong to.
        $category = isset($data['category'])
            ? $this->resolveCategory($data['category'])
            : $listing->loadMissing('category')->category;

        $attributes = $this->validatedAttributes($request, $category);

        $data['category_id'] = $category->id;
        unset($data['category']);

        $listing->update($data);

        if ($request->has('attributes') || $listing->wasChanged('category_id')) {
            $listing->syncAttributes($attributes);
        }

        return new ListingResource($listing->load('seller', 'media', 'category', 'attributeValues'));
    }

    /** Toggles the current user's saved state for a listing. */
    public function toggleSave(Request $request, Listing $listing)
    {
        $result = $request->user()->savedListings()->toggle($listing->id);

        return response()->json([
            'saved' => count($result['attached']) > 0,
        ]);
    }

    private function validated(Request $request, bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:200'],
            'description' => [$required, 'string'],
            'price' => [$required, 'numeric', 'min:0'],
            // Capped rather than merely non-negative. Postage is recovering
            // what a courier charged, and a four figure postage line on a
            // cheap item is either a mistake or a way to dodge the fee,
            // which is charged on the item alone.
            'postage_price' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'collection_only' => ['nullable', 'boolean'],
            'location' => [$required, 'string', 'max:120'],
            'category' => [$required, 'string', 'exists:categories,slug'],
            'brand' => ['nullable', 'string', Rule::in(Brands::all())],
            'condition' => ['nullable', 'string', Rule::in(self::CONDITIONS)],
        ]);
    }

    /**
     * A listing has to be filed in a leaf.
     *
     * Branches exist to be browsed through, not listed in. "Guitars" tells a
     * buyer nothing and, more to the point, carries none of the filters that
     * make a tree this deep worth having: a listing parked on a branch would
     * be invisible to every filter below it.
     */
    private function resolveCategory(string $slug): Category
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        if (! $category->is_leaf) {
            throw ValidationException::withMessages([
                'category' => "Pick a specific category. \"{$category->name}\" has more choices underneath it.",
            ]);
        }

        return $category;
    }

    /**
     * The attribute answers, checked against what this category actually asks.
     *
     * Both halves are checked, not just the values: an attribute that does
     * not belong to this category is rejected rather than stored, because a
     * record grading on a guitar lead would be a filter option nobody can
     * ever have meant to tick.
     *
     * @return array<string, string>
     */
    private function validatedAttributes(Request $request, Category $category): array
    {
        $submitted = $request->input('attributes', []);

        if (! is_array($submitted)) {
            throw ValidationException::withMessages([
                'attributes' => 'Attributes must be sent as a set of name and value pairs.',
            ]);
        }

        $allowed = Facets::forCategoryPath($category->path);
        $clean = [];

        foreach ($submitted as $name => $value) {
            // Blank means "not stated", which is always allowed: most of
            // these are optional, and forcing a seller to guess is how a
            // filter fills up with wrong answers.
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_string($name) || ! isset($allowed[$name])) {
                throw ValidationException::withMessages([
                    'attributes' => "\"{$name}\" is not something that can be set on a listing in {$category->name}.",
                ]);
            }

            if (! is_string($value) || ! Facets::isValidValue($name, $value)) {
                throw ValidationException::withMessages([
                    'attributes' => "\"{$value}\" is not one of the options for {$allowed[$name]['label']}.",
                ]);
            }

            $clean[$name] = $value;
        }

        return $clean;
    }
}
