<?php

namespace Tests\Feature;

use App\Jobs\IndexListing;
use App\Jobs\RemoveListingFromIndex;
use App\Models\Listing;
use App\Search\ListingIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Indexing happens on the queue, not on the request.
 *
 * No cluster needed: these assert on what gets dispatched and on what the
 * job does when it runs, both of which are the parts that could regress
 * silently. Whether Elasticsearch then stores the document is covered by
 * ListingSearchTest.
 */
class SearchIndexingQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_listing_queues_an_index_job_rather_than_indexing_inline(): void
    {
        config(['elasticsearch.enabled' => true]);
        Queue::fake();

        $listing = Listing::factory()->create();

        Queue::assertPushed(
            IndexListing::class,
            fn (IndexListing $job) => $job->listing->is($listing),
        );
    }

    public function test_deleting_a_listing_queues_a_removal_job(): void
    {
        config(['elasticsearch.enabled' => true]);
        $listing = Listing::factory()->create();

        Queue::fake();
        $listing->delete();

        Queue::assertPushed(
            RemoveListingFromIndex::class,
            fn (RemoveListingFromIndex $job) => $job->listingId === $listing->id,
        );
    }

    public function test_nothing_is_queued_when_search_is_disabled(): void
    {
        // Queueing work that will do nothing when it runs is just a slower
        // way of doing nothing.
        config(['elasticsearch.enabled' => false]);
        Queue::fake();

        Listing::factory()->create()->delete();

        Queue::assertNothingPushed();
    }

    public function test_the_index_job_writes_the_listing_when_it_runs(): void
    {
        config(['elasticsearch.enabled' => true]);
        $listing = Listing::factory()->create();

        $index = Mockery::mock(ListingIndex::class);
        $index->shouldReceive('index')
            ->once()
            ->with(Mockery::on(fn (Listing $indexed) => $indexed->is($listing)));

        (new IndexListing($listing))->handle($index);
    }

    public function test_the_index_job_does_nothing_when_search_is_disabled(): void
    {
        // A job already on the queue when search is switched off must not
        // write to an index nobody is reading.
        config(['elasticsearch.enabled' => false]);
        $listing = Listing::factory()->create();

        $index = Mockery::mock(ListingIndex::class);
        $index->shouldNotReceive('index');

        (new IndexListing($listing))->handle($index);
    }

    public function test_the_removal_job_removes_by_id_when_it_runs(): void
    {
        config(['elasticsearch.enabled' => true]);

        $index = Mockery::mock(ListingIndex::class);
        $index->shouldReceive('remove')->once()->with(123);

        (new RemoveListingFromIndex(123))->handle($index);
    }

    public function test_the_index_job_is_dropped_if_the_listing_has_since_been_deleted(): void
    {
        // Otherwise a delete immediately after a save leaves a job that can
        // only ever fail, retry, and eventually land in failed_jobs.
        $this->assertTrue((new IndexListing(Listing::factory()->create()))->deleteWhenMissingModels);
    }
}
