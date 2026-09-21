<?php

namespace App\Services\Safety;

use App\Models\Message;
use App\Models\Report;

/**
 * Runs every outgoing message past the scanner, records what it found, and
 * puts the worst of it in front of a moderator.
 *
 * Kept out of MessageController because two routes create messages and the
 * rule has to be identical on both. A scan that ran on replies but not on
 * the first message of a conversation would miss the opening line, which is
 * exactly where "before you buy, let's sort this out directly" lives.
 */
class MessageSafetyReviewer
{
    public function __construct(private OffPlatformScanner $scanner) {}

    /**
     * Flags the message in place, saving only when there is something to
     * save, and raises a report if a moderator should see it.
     */
    public function review(Message $message): void
    {
        $hits = $this->scanner->scan((string) $message->content);

        if ($hits === []) {
            return;
        }

        $message->safety_flags = $hits;
        $message->save();

        if ($this->scanner->isSevere($hits)) {
            $this->raiseReport($message, $hits);
        }
    }

    /**
     * A report with no reporter: the system found it, not a person.
     *
     * Deduplicated by hand rather than by the unique index on
     * reports(reporter_id, listing_id, subject_id), which does not bind
     * here: MySQL treats every NULL in a unique index as distinct, so a
     * seller sending the same line five times would have opened five
     * identical reports and buried the queue.
     */
    private function raiseReport(Message $message, array $hits): void
    {
        $conversation = $message->conversation;

        $existing = Report::query()
            ->whereNull('reporter_id')
            ->where('subject_id', $message->sender_id)
            ->where('listing_id', $conversation?->listing_id)
            ->where('status', 'OPEN')
            ->exists();

        if ($existing) {
            return;
        }

        $report = new Report([
            'reason' => 'OFF_PLATFORM_PAYMENT',

            // The signal names, not the message. A moderator can open the
            // conversation; copying the text into a second table would put
            // whatever bank details it contained somewhere else as well.
            'detail' => 'Flagged automatically in message #'.$message->id.': '.implode(', ', $hits),
        ]);

        $report->reporter_id = null;
        $report->subject_id = $message->sender_id;
        $report->listing_id = $conversation?->listing_id;
        $report->save();
    }
}
