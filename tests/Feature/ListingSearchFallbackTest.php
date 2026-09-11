<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Browsing must survive Elasticsearch being switched off or being down.
 *
 * These are the tests that matter most operationally: a search cluster is
 * one more thing that can fail, and it failing should cost relevance, not
 * the ability to look at listings at all. No cluster is needed to run them.
 */
class ListingSearchFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_browsing_uses_the_database_when_search_is_disabled(): void
    {
        config(['elasticsearch.enabled' => false]);

        Listing::factory()->create(['title' => 'Fender Stratocaster']);
        Listing::factory()->create(['title' => 'Pearl Export Drum Kit']);

        $titles = collect($this->getJson('/api/listings?search=Stratocaster')->assertOk()->json('data'))
            ->pluck('title');

        $this->assertSame(['Fender Stratocaster'], $titles->all());
    }

    public function test_browsing_falls_back_to_the_database_when_the_cluster_is_unreachable(): void
    {
        // Enabled, but pointed at a port with nothing behind it: the same
        // situation as the cluster being down in production.
        config([
            'elasticsearch.enabled' => true,
            'elasticsearch.host' => 'http://127.0.0.1:9199',
            'elasticsearch.timeout' => 0.5,
        ]);

        Listing::factory()->create(['title' => 'Fender Stratocaster']);

        Log::shouldReceive('warning')->once()->withArgs(
            fn ($message) => str_contains($message, 'falling back to the database')
        );

        $titles = collect($this->getJson('/api/listings?search=Stratocaster')->assertOk()->json('data'))
            ->pluck('title');

        $this->assertSame(['Fender Stratocaster'], $titles->all());
    }

    public function test_a_listing_still_saves_when_the_cluster_is_unreachable(): void
    {
        // The observer writes to the index on save. A seller posting gear
        // should never see that failure.
        config([
            'elasticsearch.enabled' => true,
            'elasticsearch.host' => 'http://127.0.0.1:9199',
            'elasticsearch.timeout' => 0.5,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/listings', [
            'title' => 'Fender Stratocaster',
            'description' => 'Lovely.',
            'price' => 400,
            'location' => 'Newcastle',
            'category' => 'GUITAR',
            'condition' => 'GOOD',
        ])->assertCreated();

        $this->assertDatabaseHas('listings', ['title' => 'Fender Stratocaster']);
    }
}
