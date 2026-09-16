<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A listing may not carry unlimited media.
 *
 * The per-file size rules bound a single upload, which is not the same
 * thing: at 50MB a video, a seller with nothing better to do can fill the
 * volume, and that volume also holds the database. So the limit is per
 * listing and per type, and it is enforced server side, because a limit
 * that only exists in the upload widget is not a limit.
 */
class ListingMediaLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['media.disk' => 'public']);

        $this->seller = User::factory()->create();
        $this->listing = Listing::factory()->for($this->seller, 'seller')->create();
    }

    private function upload(string $type = 'IMAGE', string $name = 'guitar.jpg')
    {
        $file = $type === 'IMAGE'
            ? UploadedFile::fake()->image($name)
            : UploadedFile::fake()->create($name, 100);

        return $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/media", [
                'file' => $file,
                'media_type' => $type,
            ]);
    }

    /**
     * Put rows straight in rather than uploading them. The limit is the
     * subject here, so the cheaper the setup the better, and going through
     * the endpoint would test the endpoint against itself.
     */
    private function fillTo(int $count, string $type = 'IMAGE'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->listing->media()->create([
                'media_type' => $type,
                'path' => "listings/{$this->listing->id}/existing-{$i}",
            ]);
        }
    }

    public function test_a_seller_can_upload_up_to_the_limit(): void
    {
        $this->fillTo(config('media.limits.IMAGE') - 1);

        $this->upload()->assertCreated();

        $this->assertSame(
            config('media.limits.IMAGE'),
            $this->listing->media()->where('media_type', 'IMAGE')->count()
        );
    }

    public function test_the_upload_after_the_limit_is_refused(): void
    {
        $this->fillTo(config('media.limits.IMAGE'));

        $this->upload()
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    /**
     * A refused upload must cost no disk. Storing first and rejecting after
     * would mean the cheapest way to fill the volume was to exceed the
     * limit on purpose.
     */
    public function test_a_refused_upload_writes_nothing_to_disk(): void
    {
        $this->fillTo(config('media.limits.IMAGE'));

        $this->upload()->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles("listings/{$this->listing->id}"));
    }

    /**
     * The limits are per type, so a listing full of photos can still take
     * the demo video that sells a guitar.
     */
    public function test_the_types_are_counted_separately(): void
    {
        $this->fillTo(config('media.limits.IMAGE'));

        $this->upload('VIDEO', 'demo.mp4')->assertCreated();
    }

    public function test_deleting_one_makes_room_again(): void
    {
        $this->fillTo(config('media.limits.IMAGE'));
        $this->upload()->assertStatus(422);

        $media = $this->listing->media()->where('media_type', 'IMAGE')->first();

        $this->actingAs($this->seller)
            ->deleteJson("/api/listings/{$this->listing->id}/media/{$media->id}")
            ->assertOk();

        $this->upload()->assertCreated();
    }
}
