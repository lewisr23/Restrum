<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StolenReport;
use App\Services\Safety\SerialNumber;
use App\Services\Safety\StolenGearRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The stolen gear register: check a serial, or report your gear stolen.
 *
 * Checking is public and needs no account, deliberately. Someone about to buy
 * a guitar on Facebook Marketplace is the person who most needs to look, and
 * a register that only works on this site's own listings protects far fewer
 * people than one anyone can search.
 */
class StolenGearController extends Controller
{
    public function __construct(private readonly StolenGearRegister $register) {}

    /**
     * What the register says about one serial.
     *
     * Enough to recognise the item and nothing about who reported it: the
     * reporter's identity and their police reference stay private. Someone
     * who finds a match is told to go to the police, who can reach the owner.
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['serial' => ['required', 'string', 'max:100']]);

        $normalized = SerialNumber::normalize($data['serial']);

        if (! SerialNumber::isMatchable($normalized)) {
            return response()->json([
                'message' => 'That serial is too short to check reliably. Enter the full serial number, at least '
                    .SerialNumber::MIN_MATCH_LENGTH.' letters or digits.',
            ], 422);
        }

        $reports = $this->register->reportsFor($data['serial']);

        return response()->json([
            'serial' => $normalized,
            'reported' => $reports->isNotEmpty(),
            'reports' => $reports->map(fn (StolenReport $report) => [
                'brand' => $report->brand,
                'description' => $report->description,
                'stolen_on' => $report->stolen_on?->toDateString(),
                'location' => $report->location,
                'reported_on' => $report->created_at->toDateString(),
                'police_reported' => $report->police_reference !== null,
            ])->values(),
        ]);
    }

    /** The signed-in user's own reports. */
    public function index(Request $request): JsonResponse
    {
        $reports = $request->user()->stolenReports()->latest()->get();

        return response()->json(['data' => $reports->map(fn (StolenReport $r) => $this->mine($r))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:500'],
            'stolen_on' => ['nullable', 'date', 'before_or_equal:today'],
            'location' => ['nullable', 'string', 'max:120'],
            'police_reference' => ['nullable', 'string', 'max:60'],
        ]);

        if (! SerialNumber::isMatchable(SerialNumber::normalize($data['serial_number']))) {
            return response()->json([
                'message' => 'That serial is too short to match reliably. Enter the full serial number.',
                'errors' => ['serial_number' => ['Enter the full serial number, at least '.SerialNumber::MIN_MATCH_LENGTH.' letters or digits.']],
            ], 422);
        }

        $report = $request->user()->stolenReports()->create($data);

        $this->register->checkReport($report);

        return response()->json(['data' => $this->mine($report->fresh())], 201);
    }

    /**
     * Got it back. The report stops matching, but is kept: it is the record
     * of what happened, and a moderator reviewing an old flag needs it.
     */
    public function recovered(Request $request, StolenReport $report): JsonResponse
    {
        abort_unless($report->user_id === $request->user()->id, 404);

        $report->status = 'RECOVERED';
        $report->recovered_at = now();
        $report->save();

        return response()->json(['data' => $this->mine($report)]);
    }

    /** @return array<string, mixed> */
    private function mine(StolenReport $report): array
    {
        return [
            'id' => $report->id,
            'serial_number' => $report->serial_number,
            'brand' => $report->brand,
            'description' => $report->description,
            'stolen_on' => $report->stolen_on?->toDateString(),
            'location' => $report->location,
            'police_reference' => $report->police_reference,
            'status' => $report->status,
            'reported_on' => $report->created_at->toDateString(),
        ];
    }
}
