<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploaded media has to be able to live somewhere other than local disk.
 *
 * A container filesystem does not survive a restart, so on any hosted
 * deployment local storage means a seller's photos disappear on the next
 * deploy, silently, with the database still confidently listing them.
 * These tests pin the behaviour that makes the storage location a config
 * value rather than something baked into every row.
 */
class MediaStorageTest extends TestCase
{
    use RefreshDatabase;

    private function upload(string $disk): array
    {
        Storage::fake($disk);
        config(['media.disk' => $disk]);

        $seller = User::factory()->create();
        $listing = Listing::factory()->for($seller, 'seller')->create();

        $response = $this->actingAs($seller)
            ->postJson("/api/listings/{$listing->id}/media", [
                'file' => UploadedFile::fake()->image('guitar.jpg'),
                'media_type' => 'IMAGE',
            ])
            ->assertCreated();

        return [$listing, $response];
    }

    public function test_media_is_written_to_whichever_disk_is_configured(): void
    {
        // Not the public disk, and nothing in the code names it: if the disk
        // were still hardcoded this would write to the wrong place.
        [$listing] = $this->upload('media-cloud');

        $media = ListingMedia::firstOrFail();

        Storage::disk('media-cloud')->assertExists($media->path);
        $this->assertStringStartsWith("listings/{$listing->id}/", $media->path);
    }

    public function test_the_stored_path_has_no_disk_specific_prefix_in_it(): void
    {
        // The old schema stored "/storage/listings/4/x.jpg", which is a
        // local URL rather than a location, and is why moving disks meant
        // rewriting every row.
        $this->upload('media-cloud');

        $media = ListingMedia::firstOrFail();

        $this->assertStringNotContainsString('/storage/', $media->path);
        $this->assertStringNotContainsString('http', $media->path);
    }

    public function test_the_api_still_returns_a_url_for_each_media_item(): void
    {
        // url is computed from the disk now rather than stored, so the
        // response shape the frontend reads has to be unchanged.
        [$listing] = $this->upload('media-cloud');

        $this->getJson("/api/listings/{$listing->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['media' => [['id', 'media_type', 'url']]]]);

        $this->assertNotNull($this->getJson("/api/listings/{$listing->id}")->json('data.media.0.url'));
    }

    public function test_the_same_row_serves_from_a_new_location_when_the_disk_changes(): void
    {
        // The point of the change: moving storage to a bucket or a CDN is a
        // config edit, not a rewrite of every row.
        $this->upload('media-cloud');
        $media = ListingMedia::firstOrFail();
        $path = $media->path;

        config([
            'filesystems.disks.cdn' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/cdn'),
                'url' => 'https://cdn.example.test/media',
            ],
            'media.disk' => 'cdn',
        ]);

        $this->assertSame("https://cdn.example.test/media/{$path}", $media->fresh()->url);
        // The row itself never changed.
        $this->assertSame($path, $media->fresh()->path);
    }

    public function test_deleting_media_removes_the_file_from_the_disk(): void
    {
        [$listing] = $this->upload('media-cloud');
        $media = ListingMedia::firstOrFail();
        $path = $media->path;

        Storage::disk('media-cloud')->assertExists($path);

        $this->actingAs($listing->seller)
            ->deleteJson("/api/listings/{$listing->id}/media/{$media->id}")
            ->assertOk();

        Storage::disk('media-cloud')->assertMissing($path);
        $this->assertDatabaseMissing('listing_media', ['id' => $media->id]);
    }
}
