<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Drafting\DraftUnavailable;
use App\Services\Drafting\ListingDrafter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Photo to listing. Takes the seller's photos, returns a draft for the sell
 * form. Nothing is stored: the photos are read and forgotten, and they reach
 * the listing, if at all, through the ordinary media upload afterwards.
 */
class ListingDraftController extends Controller
{
    public function __construct(private readonly ListingDrafter $drafter) {}

    /** Whether to show the feature at all. False when no API key is set. */
    public function status(): JsonResponse
    {
        return response()->json(['available' => $this->drafter->isConfigured()]);
    }

    public function store(Request $request): JsonResponse
    {
        // The formats Claude accepts, and 5MB each, which is its per-image
        // ceiling. The frontend shrinks photos well below that before
        // sending, so this only bites a client that did not.
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:3'],
            'photos.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ]);

        $images = array_map(static fn ($photo) => [
            'media_type' => $photo->getMimeType(),
            'data' => base64_encode($photo->get()),
        ], $request->file('photos'));

        try {
            $draft = $this->drafter->draft($images);
        } catch (DraftUnavailable $e) {
            return response()->json([
                'message' => 'Filling in from photos is not available right now. You can still write the listing yourself.',
            ], 503);
        }

        return response()->json(['data' => $draft]);
    }
}
