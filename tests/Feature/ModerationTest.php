<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reporting, and the lever behind it.
 *
 * Before this there was neither: someone who spotted a stolen instrument or
 * a seller pushing bank transfers had no button, and the operator had no
 * action short of editing the database. Reporting without tooling is a
 * suggestion box.
 */
class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $reporter;

    private User $seller;

    private User $admin;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reporter = User::factory()->create();
        $this->seller = User::factory()->create();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->listing = Listing::factory()->for($this->seller, 'seller')->create();
    }

    public function test_anyone_signed_in_can_report_a_listing(): void
    {
        $this->actingAs($this->reporter)
            ->postJson("/api/listings/{$this->listing->id}/report", [
                'reason' => 'STOLEN',
                'detail' => 'Serial number matches one reported stolen.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('reports', [
            'listing_id' => $this->listing->id,
            'subject_id' => $this->seller->id,
            'reason' => 'STOLEN',
            'status' => 'OPEN',
        ]);
    }

    public function test_reporting_the_same_thing_twice_does_not_stack(): void
    {
        foreach ([1, 2] as $_) {
            $this->actingAs($this->reporter)
                ->postJson("/api/listings/{$this->listing->id}/report", ['reason' => 'SCAM'])
                ->assertSuccessful();
        }

        $this->assertDatabaseCount('reports', 1);
    }

    public function test_you_cannot_report_yourself(): void
    {
        $this->actingAs($this->reporter)
            ->postJson("/api/users/{$this->reporter->id}/report", ['reason' => 'ABUSE'])
            ->assertStatus(422);
    }

    /**
     * 404 not 403, so the moderation tooling does not announce itself.
     */
    public function test_the_admin_queue_is_invisible_to_normal_users(): void
    {
        $this->actingAs($this->reporter)
            ->getJson('/api/admin/reports')
            ->assertNotFound();
    }

    public function test_an_admin_sees_open_reports(): void
    {
        $this->actingAs($this->reporter)
            ->postJson("/api/listings/{$this->listing->id}/report", ['reason' => 'COUNTERFEIT'])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'COUNTERFEIT');
    }

    /**
     * Suspension has to actually stop them, or it is a flag nobody feels.
     */
    public function test_suspending_a_seller_logs_them_out_and_takes_their_stock_down(): void
    {
        $this->seller->createToken('session');

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$this->seller->id}/suspend", ['reason' => 'Took payment, posted nothing.'])
            ->assertOk();

        $this->assertNotNull($this->seller->fresh()->suspended_at);
        $this->assertSame(0, $this->seller->fresh()->tokens()->count());
        $this->assertSame('SOLD', $this->listing->fresh()->status);
    }

    public function test_a_suspended_user_cannot_log_back_in(): void
    {
        $user = User::factory()->create(['email' => 'banned@example.com']);
        $user->suspended_at = now();
        $user->save();

        $this->postJson('/api/login', [
            'login' => 'banned@example.com',
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_an_admin_can_resolve_a_report(): void
    {
        $this->actingAs($this->reporter)
            ->postJson("/api/listings/{$this->listing->id}/report", ['reason' => 'SCAM'])
            ->assertCreated();

        $report = Report::firstOrFail();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/reports/{$report->id}/resolve", [
                'status' => 'ACTIONED',
                'resolution_note' => 'Seller suspended.',
            ])
            ->assertOk();

        $this->assertSame('ACTIONED', $report->fresh()->status);
        $this->assertNotNull($report->fresh()->resolved_at);
    }

    public function test_an_admin_can_take_a_listing_down_without_touching_the_seller(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/admin/listings/{$this->listing->id}/remove")
            ->assertOk();

        $this->assertSame('SOLD', $this->listing->fresh()->status);
        $this->assertNull($this->seller->fresh()->suspended_at);
    }

    public function test_admin_status_is_not_published(): void
    {
        $this->getJson("/api/users/{$this->admin->id}")
            ->assertOk()
            ->assertJsonMissing(['is_admin' => true]);
    }
}
