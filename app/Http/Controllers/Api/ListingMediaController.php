<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ListingMediaController extends Controller
{
    /**
     * Per-type file constraints. Kept as separate rule sets rather than one
     * generic "any file under 50MB" rule - a 40MB JPEG upload is almost
     * always a mistake, not a real photo, and should fail validation rather
     * than quietly accepted.
     */
    private const RULES = [
        'IMAGE' => ['max:8192', 'mimes:jpg,jpeg,png,webp'],
        'AUDIO' => ['max:20480', 'mimes:mp3,wav,m4a,ogg'],
        'VIDEO' => ['max:51200', 'mimes:mp4,mov,webm'],
    ];

    public function store(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $data = $request->validate([
            'media_type' => ['required', 'string', 'in:IMAGE,AUDIO,VIDEO'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $request->validate([
            'file' => ['required', 'file', ...self::RULES[$data['media_type']]],
        ]);

        // The disk is configuration, not something this controller decides:
        // local in development, object storage anywhere with a container
        // filesystem. See config/media.php.
        $path = $request->file('file')->store(
            "listings/{$listing->id}",
            config('media.disk'),
        );

        if ($path === false) {
            // A failed upload that returns 201 with a broken URL is worse
            // than an error, because nothing downstream can tell.
            return response()->json(['message' => 'Could not store the uploaded file.'], 500);
        }

        $media = $listing->media()->create([
            'media_type' => $data['media_type'],
            'path' => $path,
            'label' => $data['label'] ?? null,
        ]);
        // uploaded_at is a DB-level useCurrent() default, not part of this
        // payload - without refreshing, it's silently absent from the raw
        // model JSON below (not null, just missing entirely, which is why
        // this didn't show up as an obvious bug the first time around).
        $media->refresh();

        return response()->json($media, 201);
    }

    public function destroy(Request $request, Listing $listing, ListingMedia $media)
    {
        $this->authorize('update', $listing);

        if ($media->listing_id !== $listing->id) {
            abort(404);
        }

        // The stored path is the path on the disk, so deleting needs no
        // string surgery on a URL the way it did when URLs were what the
        // table held.
        Storage::disk(config('media.disk'))->delete($media->path);

        $media->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
