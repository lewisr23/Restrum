<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Report;
use App\Models\User;
use App\Services\Safety\OffPlatformScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Messages steering a sale off Restrum.
 *
 * This is the attack escrow cannot see. Every protection on the site - money
 * held until delivery, refunds, chargebacks, Stripe's identity check on the
 * seller - is bypassed entirely by one sentence in the chat, and messaging
 * was previously the one surface with nothing watching it.
 */
class OffPlatformPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function scanner(): OffPlatformScanner
    {
        return app(OffPlatformScanner::class);
    }

    /**
     * The case that matters most: an ordinary message about buying a guitar
     * must not be warned about. A warning on every second message is a
     * warning nobody reads, which is worse than none at all.
     */
    public function test_ordinary_messages_are_left_alone(): void
    {
        $clean = [
            'Still available? I could collect Saturday.',
            'Would you take 450 for it?',
            'Happy to post it tomorrow, tracked 48.',
            'Bought in 2019, serial 12345678, barely played.',
            'The neck is straight and the frets have plenty left.',
            'I can send more photos of the back if that helps.',
            'Does it come with the original case?',
        ];

        foreach ($clean as $text) {
            $this->assertSame([], $this->scanner()->scan($text), "Flagged an innocent message: {$text}");
        }
    }

    /**
     * A six digit number in two digit groups is at least as likely to be a
     * serial off the back of an amp as a sort code, so on its own it is a
     * caution rather than something a moderator is woken up for.
     */
    public function test_a_bare_six_digit_number_is_not_treated_as_bank_details(): void
    {
        $hits = $this->scanner()->scan('The serial is 12-34-56 on the back');

        $this->assertSame(['sort_code_digits'], $hits);
        $this->assertFalse($this->scanner()->isSevere($hits));
    }

    public function test_a_sort_code_with_an_account_number_is_severe(): void
    {
        $hits = $this->scanner()->scan('40-11-22 12345678, J Smith');

        $this->assertContains('bank_account_digits', $hits);
        $this->assertTrue($this->scanner()->isSevere($hits));
    }

    #[DataProvider('severeMessages')]
    public function test_the_obvious_pitches_are_severe(string $text): void
    {
        $hits = $this->scanner()->scan($text);

        $this->assertNotSame([], $hits, "Missed entirely: {$text}");
        $this->assertTrue($this->scanner()->isSevere($hits), "Not treated as severe: {$text}");
    }

    public static function severeMessages(): array
    {
        return [
            ['Bank transfer is easier, sort code 40-11-22'],
            ['Send it friends and family and I will knock 50 off'],
            ['Can you do it outside the site? No fees that way'],
            ['Pay by steam gift card and I will post it today'],
            ['I only take bitcoin these days'],
            ['Western Union is fine if that is easier'],
            ['Just transfer it straight to my account, cuts out the middleman'],
        ];
    }

    #[DataProvider('notableMessages')]
    public function test_contact_swaps_are_a_caution_not_an_alarm(string $text): void
    {
        $hits = $this->scanner()->scan($text);

        $this->assertNotSame([], $hits, "Missed entirely: {$text}");
        $this->assertFalse($this->scanner()->isSevere($hits), "Over-escalated: {$text}");
    }

    public static function notableMessages(): array
    {
        return [
            ['WhatsApp me on 07700 900123'],
            ['Easier on email, bob@example.com'],
            ['Do you have paypal?'],
        ];
    }

    public function test_a_flagged_first_message_is_stored_with_its_flags(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/messages", [
            'content' => 'I can do bank transfer if that avoids the fees',
        ])->assertCreated();

        $flags = $response->json('message.safety_flags');

        $this->assertNotEmpty($flags);
        $this->assertContains('bank_transfer', $flags);
    }

    public function test_a_flagged_reply_is_stored_with_its_flags(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/messages", [
            'content' => 'Is this still going?',
        ])->assertCreated();

        $conversationId = $buyer->conversationsAsBuyer()->firstOrFail()->id;

        $response = $this->actingAs($listing->seller)
            ->postJson("/api/conversations/{$conversationId}/messages", [
                'content' => 'Sort code 40-11-22, account 12345678',
            ])
            // 201, not 200: a JsonResource wrapping a model that was just
            // created answers Created of its own accord.
            ->assertCreated();

        $this->assertContains('bank_account_digits', $response->json('data.safety_flags'));
    }

    /**
     * The message still goes through. Blocking it would teach whoever sent
     * it to reword rather than stop, and the person who needs protecting is
     * the one reading it.
     */
    public function test_a_flagged_message_is_still_delivered(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/messages", [
            'content' => 'Bank transfer only please',
        ])->assertCreated();

        $this->assertDatabaseHas('messages', ['content' => 'Bank transfer only please']);
    }

    public function test_a_clean_message_carries_no_flags(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/messages", [
            'content' => 'Would you take 450? I can collect from Leeds.',
        ])->assertCreated();

        $this->assertNull($response->json('message.safety_flags'));
    }

    public function test_a_severe_message_opens_a_report_against_its_sender(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/messages", [
            'content' => 'Pay me friends and family and save the fees',
        ])->assertCreated();

        $report = Report::query()->whereNull('reporter_id')->firstOrFail();

        $this->assertSame('OFF_PLATFORM_PAYMENT', $report->reason);
        $this->assertSame($buyer->id, $report->subject_id);
        $this->assertSame($listing->id, $report->listing_id);
        $this->assertSame('OPEN', $report->status);
    }

    public function test_a_caution_does_not_open_a_report(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/messages", [
            'content' => 'WhatsApp me on 07700 900123',
        ])->assertCreated();

        $this->assertSame(0, Report::query()->whereNull('reporter_id')->count());
    }

    /**
     * Repeating themselves must not repeat the report. The unique index on
     * the reports table does not stop this, because MySQL treats each NULL
     * reporter as a different reporter.
     */
    public function test_repeating_the_pitch_does_not_bury_the_moderation_queue(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create();

        foreach (['Bank transfer is easier', 'Seriously, bank transfer', 'bank transfer?'] as $text) {
            $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/messages", [
                'content' => $text,
            ])->assertCreated();
        }

        $this->assertSame(1, Report::query()->whereNull('reporter_id')->count());
    }

    /** An admin sees automatic reports in the same queue as reported ones. */
    public function test_automatic_reports_reach_the_moderation_queue(): void
    {
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/messages", [
            'content' => 'Send a gift card code and it is yours',
        ])->assertCreated();

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'OFF_PLATFORM_PAYMENT')
            ->assertJsonPath('data.0.reporter', null);
    }
}
