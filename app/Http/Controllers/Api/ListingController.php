<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Services\PriceInsightService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ListingController extends Controller
{
    private const CATEGORIES = ['GUITAR', 'DRUMS', 'MICROPHONE', 'SYNTHS', 'AUDIO_EQUIPMENT'];

    private const CONDITIONS = ['MINT', 'EXCELLENT', 'GOOD', 'FAIR'];

    public function __construct(private readonly PriceInsightService $priceInsight) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'in:'.implode(',', self::CATEGORIES)],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        // index has no auth:sanctum middleware (it's public), so nothing has
        // told the auth manager which guard to use - plain user() would
        // silently always be null here even with a valid Bearer token.
        // Naming the guard explicitly resolves it regardless.
        $viewerId = $request->user('sanctum')?->id;

        $listings = Listing::query()
            ->with('seller', 'media')
            ->when($viewerId, fn ($q) => $q->with(['savedBy' => fn ($q) => $q->where('users.id', $viewerId)]))
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->when($data['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($data['min_price'] ?? null, fn ($q, $min) => $q->where('price', '>=', $min))
            ->when($data['max_price'] ?? null, fn ($q, $max) => $q->where('price', '<=', $max))
            ->latest()
            ->paginate(20);

        return ListingResource::collection($listings);
    }

    /**
     * The signed-in user's saved listings. Registered BEFORE the {listing}
     * show route - Laravel matches routes in registration order, and without
     * that ordering "saved" would be swallowed by {listing} as if it were an id.
     */
    public function saved(Request $request)
    {
        $listings = $request->user()->savedListings()
            ->with('seller', 'media')
            ->latest('saved_listings.created_at')
            ->paginate(20);

        // Every row here is, by definition, saved by the viewer - set the
        // flag directly rather than re-querying the pivot a second time.
        $listings->getCollection()->each(
            fn ($listing) => $listing->setRelation('savedBy', collect([true]))
        );

        return ListingResource::collection($listings);
    }

    public function show(Request $request, Listing $listing)
    {
        $listing->load('seller', 'media');

        // Same reason as index() above - show has no auth:sanctum middleware.
        if ($viewerId = $request->user('sanctum')?->id) {
            $listing->load(['savedBy' => fn ($q) => $q->where('users.id', $viewerId)]);
        }

        return (new ListingResource($listing))->additional([
            'price_insight' => $this->priceInsight->forListing($listing),
        ]);
    }

    /**
     * Direct purchase at the listed price, alongside offer negotiation.
     * Deliberately no payment processing - this is scoped as peer-to-peer
     * (buyer and seller arrange payment directly), not a payments platform.
     * No Order/Purchase record either, for the same reason: there is
     * genuinely nothing to process or store beyond "this is no longer for
     * sale" - inventing one would be building commerce infrastructure this
     * project explicitly isn't taking on.
     */
    public function buy(Request $request, Listing $listing)
    {
        if ($request->user()->id === $listing->seller_id) {
            throw ValidationException::withMessages([
                'listing' => 'You cannot buy your own listing.',
            ]);
        }

        if ($listing->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'listing' => 'This listing is no longer available.',
            ]);
        }

        $listing->status = 'SOLD';
        $listing->save();

        return new ListingResource($listing->load('seller', 'media'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        // 'condition' is fillable, so merging a default in here before make()
        // works via normal mass assignment.
        $data['condition'] ??= 'GOOD';

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

        // 'media' is loaded even though a brand-new listing has none yet:
        // ListingResource only emits that key when the relation is loaded, so
        // leaving it off makes the field silently ABSENT rather than an empty
        // array, and callers then have to handle two shapes for one resource.
        return new ListingResource($listing->load('seller', 'media'));
    }

    public function update(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $listing->update($this->validated($request, forUpdate: true));

        return new ListingResource($listing->load('seller', 'media'));
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
            'location' => [$required, 'string', 'max:120'],
            'category' => [$required, 'string', 'in:'.implode(',', self::CATEGORIES)],
            'condition' => ['nullable', 'string', 'in:'.implode(',', self::CONDITIONS)],
        ]);
    }
}
