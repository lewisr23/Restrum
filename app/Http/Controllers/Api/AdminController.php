<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The lever. Small on purpose.
 *
 * Everything here is reversible except removing a listing, and nothing here
 * deletes a user: suspension sets a flag, because deleting an account
 * cascades through its listings and orders and would take a real buyer's
 * purchase record with it.
 */
class AdminController extends Controller
{
    /** The queue: open reports, oldest first, because age is the backlog. */
    public function reports(Request $request)
    {
        $reports = Report::query()
            ->with(['reporter:id,username', 'subject:id,username,suspended_at', 'listing:id,title,status'])
            ->where('status', $request->input('status', 'OPEN'))
            ->orderBy('created_at')
            ->paginate(50);

        return response()->json($reports);
    }

    public function resolve(Request $request, Report $report)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['ACTIONED', 'DISMISSED'])],
            'resolution_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $report->status = $data['status'];
        $report->resolution_note = $data['resolution_note'] ?? null;
        $report->resolved_at = now();
        $report->save();

        return response()->json(['data' => $report]);
    }

    public function suspend(Request $request, User $user)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $user->suspended_at = now();
        $user->suspension_reason = $data['reason'];
        $user->save();

        // Log them out everywhere. A suspension that leaves the existing
        // session working is a suspension in name only.
        $user->tokens()->delete();

        // Their stock comes down with them. Leaving live listings up from a
        // suspended seller means buyers can still pay someone who has been
        // stopped for taking money and not posting.
        $user->listings()->where('status', 'ACTIVE')->update(['status' => 'SOLD']);

        return response()->json(['message' => "{$user->username} is suspended."]);
    }

    public function reinstate(User $user)
    {
        $user->suspended_at = null;
        $user->suspension_reason = null;
        $user->save();

        return response()->json(['message' => "{$user->username} is reinstated."]);
    }

    /**
     * Take a listing down without touching the seller.
     *
     * Marked SOLD rather than deleted, for the same reason suspension does
     * not delete: an order may point at it, and orders cascade.
     */
    public function removeListing(Listing $listing)
    {
        $listing->status = 'SOLD';
        $listing->save();

        return response()->json(['message' => 'Listing removed from sale.']);
    }
}
