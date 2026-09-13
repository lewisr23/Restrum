<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    /**
     * Gear that actually exists, filed where it actually belongs.
     *
     * Random titles against random categories used to be fine, when there
     * were five categories and nothing depended on the pairing. With brands
     * and per-category attributes in play, a "Fender Stratocaster" filed
     * under cassettes would make every test that reads a filter panel
     * meaningless, so the three fields travel together.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const GEAR = [
        ['solid-body-electric-guitars', 'Fender Stratocaster', 'Fender'],
        ['solid-body-electric-guitars', 'Gibson Les Paul Standard', 'Gibson'],
        ['dreadnought-guitars', 'Yamaha FG800 Dreadnought', 'Yamaha'],
        ['4-string-bass-guitars', 'Fender Precision Bass', 'Fender'],
        ['delay-pedals', 'Boss DD-7 Digital Delay', 'Boss'],
        ['overdrive-distortion-pedals', 'Ibanez Tube Screamer', 'Ibanez'],
        ['guitar-combo-amps', 'Fender Blues Junior', 'Fender'],
        ['rock-fusion-drum-kits', 'Pearl Export Drum Kit', 'Pearl'],
        ['ride-cymbals', 'Zildjian A Custom Ride 20"', 'Zildjian'],
        ['analogue-synthesisers', 'Roland Juno-106', 'Roland'],
        ['dynamic-microphones', 'Shure SM58', 'Shure'],
        ['usb-audio-interfaces', 'Focusrite Scarlett 2i2', 'Focusrite'],
        ['active-studio-monitors', 'Yamaha HS5 Monitors', 'Yamaha'],
        ['direct-drive-turntables', 'Technics SL-1210 MK2', 'Technics'],
        ['belt-drive-turntables', 'Rega Planar 2', 'Rega'],
        ['cassette-decks', 'Nakamichi BX-100 Cassette Deck', 'Nakamichi'],
        ['12-albums-lps', 'Blue Train, 12" LP', 'Blue Note'],
        ['7-singles', 'Northern Soul 7" Single', 'Atlantic'],
        ['album-cassettes', 'Doolittle, Album Cassette', 'Rough Trade'],
        ['jack-to-jack-instrument-cables', 'Van Damme Jack Cable 6m', 'Van Damme'],
        ['pedal-power-supplies', 'Truetone 1 Spot Pro CS7', 'Truetone'],
        ['guitar-straps', 'Leather Guitar Strap', 'Fender'],
        ['clip-on-tuners', 'Snark SN-5X Clip-On Tuner', 'Snark'],
        ['amp-footswitches', 'Two-Button Amp Footswitch', 'Boss'],
    ];

    public function definition(): array
    {
        [$categorySlug, $title, $brand] = fake()->randomElement(self::GEAR);

        return [
            'title' => $title,
            'description' => fake()->sentence(12),
            'price' => fake()->randomFloat(2, 40, 2000),
            'location' => fake()->randomElement(['Newcastle', 'Leeds', 'Manchester', 'Bristol']),
            'category_id' => self::categoryId($categorySlug),
            'brand' => $brand,
            'condition' => fake()->randomElement(['MINT', 'EXCELLENT', 'GOOD', 'FAIR']),
            'status' => 'ACTIVE',
            'seller_id' => User::factory(),
        ];
    }

    /** Files this listing in a named leaf, for tests that care where it sits. */
    public function inCategory(string $slug): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => self::categoryId($slug),
        ]);
    }

    public function brand(string $brand): static
    {
        return $this->state(fn (array $attributes) => ['brand' => $brand]);
    }

    public function sold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'SOLD',
        ]);
    }

    /**
     * Resolved per call rather than cached on the class.
     *
     * A static cache would survive between tests while the database under it
     * is rebuilt, and the ids would quietly stop matching anything.
     */
    private static function categoryId(string $slug): int
    {
        $id = Category::where('slug', $slug)->value('id');

        if ($id === null) {
            throw new \RuntimeException(
                "No category with slug '{$slug}'. The taxonomy may have been edited without updating ListingFactory."
            );
        }

        return $id;
    }
}
