<?php

namespace App\Services\Safety;

use App\Models\Listing;
use App\Models\Report;
use App\Models\StolenReport;
use App\Notifications\StolenGearSpotted;
use Illuminate\Support\Collection;

/**
 * Matching listings against gear reported stolen, in both directions.
 *
 * A listing is checked when its serial is set, and the live listings are
 * checked when a theft is reported, because either can come first: the
 * guitar is often listed before its owner has noticed it has gone.
 *
 * A match never accuses anyone in public. It opens a report for a moderator,
 * holds checkout on the listing (see isHeld), and tells the person who
 * reported the theft. Serials collide, and a seller who bought a guitar in
 * good faith is a victim too; deciding which case this is belongs to a
 * person looking at both sides, not to a string comparison.
 */
class StolenGearRegister
{
    /**
     * Active reports for a serial, however it was typed.
     *
     * @return Collection<int, StolenReport>
     */
    public function reportsFor(?string $serial): Collection
    {
        $normalized = SerialNumber::normalize($serial);

        if (! SerialNumber::isMatchable($normalized)) {
            return collect();
        }

        return StolenReport::where('serial_normalized', $normalized)
            ->where('status', 'ACTIVE')
            ->orderBy('created_at')
            ->get();
    }

    /** Check one listing against the register, after its serial was set. */
    public function checkListing(Listing $listing): void
    {
        $serial = $listing->passport?->serial_number;

        foreach ($this->reportsFor($serial) as $report) {
            if ($this->plausible($report, $listing)) {
                $this->flag($listing, $report);
            }
        }
    }

    /** Check the live listings against a report that was just filed. */
    public function checkReport(StolenReport $report): void
    {
        if (! SerialNumber::isMatchable($report->serial_normalized)) {
            return;
        }

        $listings = Listing::query()
            ->where('status', 'ACTIVE')
            ->whereHas('passport', fn ($q) => $q->where('serial_normalized', $report->serial_normalized))
            ->get();

        foreach ($listings as $listing) {
            if ($this->plausible($report, $listing)) {
                $this->flag($listing, $report);
            }
        }
    }

    /**
     * Whether checkout on a listing is on hold pending a stolen gear review.
     *
     * Read from the report queue rather than a flag on the listing, so that a
     * moderator resolving the report is all it takes to release it: there is
     * no second switch to forget.
     */
    public function isHeld(Listing $listing): bool
    {
        return Report::query()
            ->whereNull('reporter_id')
            ->where('listing_id', $listing->id)
            ->where('reason', 'STOLEN')
            ->where('status', 'OPEN')
            ->exists();
    }

    /**
     * A shared serial across two different makers is a coincidence, not a
     * stolen guitar. Only when both sides name a brand can that be told,
     * so a missing brand on either side still counts as a match.
     */
    private function plausible(StolenReport $report, Listing $listing): bool
    {
        if ($report->user_id === $listing->seller_id) {
            // Someone reporting their own listing's serial stolen is either
            // testing the feature or has got their gear back and relisted it.
            // Neither is worth a moderator's time or a hold.
            return false;
        }

        if ($report->brand === null || $listing->brand === null) {
            return true;
        }

        return strcasecmp(trim($report->brand), trim($listing->brand)) === 0;
    }

    /**
     * Open the moderator report, once, and tell the owner.
     *
     * Deduplicated by hand for the same reason MessageSafetyReviewer does it:
     * a unique index does not bind on the NULL reporter of a system report.
     */
    private function flag(Listing $listing, StolenReport $report): void
    {
        $marker = 'stolen report #'.$report->id;

        $existing = Report::query()
            ->whereNull('reporter_id')
            ->where('listing_id', $listing->id)
            ->where('reason', 'STOLEN')
            ->where('detail', 'like', '%'.$marker.'%')
            ->exists();

        if ($existing) {
            return;
        }

        $moderation = new Report([
            'reason' => 'STOLEN',
            'detail' => 'Serial matches '.$marker.' ('.$report->description
                .($report->police_reference ? ', police ref '.$report->police_reference : ', no police ref given')
                .'). Checkout is on hold until this is resolved.',
        ]);
        $moderation->reporter_id = null;
        $moderation->listing_id = $listing->id;
        $moderation->subject_id = $listing->seller_id;
        $moderation->save();

        $report->user?->notify(new StolenGearSpotted($listing));
    }
}
