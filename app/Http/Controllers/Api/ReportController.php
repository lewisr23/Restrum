<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    private const REASONS = [
        'SCAM',
        'STOLEN',
        'COUNTERFEIT',
        'OFF_PLATFORM_PAYMENT',
        'PROHIBITED',
        'ABUSE',
        'OTHER',
    ];

    public function listing(Request $request, Listing $listing)
    {
        return $this->record($request, $listing, $listing->seller_id);
    }

    public function user(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            throw ValidationException::withMessages([
                'subject' => 'You cannot report yourself.',
            ]);
        }

        return $this->record($request, null, $user->id);
    }

    private function record(Request $request, ?Listing $listing, int $subjectId)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(self::REASONS)],
            'detail' => ['nullable', 'string', 'max:2000'],
        ]);

        $reporter = $request->user();

        $already = Report::query()
            ->where('reporter_id', $reporter->id)
            ->where('listing_id', $listing?->id)
            ->where('subject_id', $subjectId)
            ->exists();

        if ($already) {
            // Not an error worth making a fuss about. Reporting twice is
            // usually someone unsure the first one worked, and telling them
            // it is already logged is the honest answer.
            return response()->json(['message' => 'You have already reported this. We are looking at it.']);
        }

        $report = new Report($data);
        $report->reporter_id = $reporter->id;
        $report->listing_id = $listing?->id;
        $report->subject_id = $subjectId;
        $report->save();

        return response()->json([
            'message' => 'Thanks. We will look at this.',
            'data' => ['id' => $report->id],
        ], 201);
    }
}
