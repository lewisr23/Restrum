<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PassportEntryResource;
use App\Http\Resources\PassportResource;
use App\Models\Listing;
use App\Models\PassportEntry;
use Illuminate\Http\Request;

class PassportController extends Controller
{
    private const ENTRY_TYPES = ['ORIGINAL_PURCHASE', 'OWNERSHIP_CHANGE', 'SERVICE', 'REPAIR', 'MODIFICATION', 'OTHER'];

    /**
     * Public - a listing with no gear history yet is a normal state, not an
     * error, so this returns null data with 200 rather than 404.
     */
    public function show(Listing $listing)
    {
        $passport = $listing->passport()->with(['entries' => fn ($q) => $q->orderBy('created_at')])->first();

        return response()->json(['data' => $passport ? new PassportResource($passport) : null]);
    }

    /**
     * Creates the passport row on first use - a seller adding gear history
     * shouldn't need a separate "create passport" step before they can log
     * an entry.
     */
    public function addEntry(Request $request, Listing $listing)
    {
        $this->authorize('update', $listing);

        $data = $request->validate([
            'entry_type' => ['required', 'string', 'in:'.implode(',', self::ENTRY_TYPES)],
            'description' => ['required', 'string', 'max:2000'],
            'event_date' => ['nullable', 'date'],
        ]);

        $passport = $listing->passport()->firstOrCreate([]);

        $entry = $passport->entries()->create($data);

        // ->response()->setStatusCode() rather than response()->json($resource) -
        // the latter skips Laravel's automatic {"data": ...} envelope that a
        // directly-returned Resource gets, which made this endpoint's response
        // shape silently inconsistent with updateEntry() below.
        return (new PassportEntryResource($entry))->response()->setStatusCode(201);
    }

    public function updateEntry(Request $request, Listing $listing, PassportEntry $entry)
    {
        $this->authorize('update', $listing);

        // Route model binding resolves $entry globally by id - confirm it
        // actually belongs to THIS listing's passport, not just any passport.
        if ($entry->passport->listing_id !== $listing->id) {
            abort(404);
        }

        $data = $request->validate([
            'entry_type' => ['sometimes', 'string', 'in:'.implode(',', self::ENTRY_TYPES)],
            'description' => ['sometimes', 'string', 'max:2000'],
            'event_date' => ['nullable', 'date'],
        ]);

        $entry->update($data);

        return new PassportEntryResource($entry);
    }
}
