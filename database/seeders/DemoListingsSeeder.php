<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A spread of listings wide enough to see the filters work.
 *
 * Not random. A faceted filter panel is only legible against data that has
 * something to filter: five records of three different sizes and two speeds
 * show what the panel does, where fifty random rows show nothing. So these
 * are written out by hand, concentrated in the corners of the shop where the
 * filtering is most interesting, and honest about being demo data.
 *
 * Run with: php artisan db:seed --class=DemoListingsSeeder
 */
class DemoListingsSeeder extends Seeder
{
    /**
     * [category slug, title, brand, price, condition, attributes]
     *
     * @var array<int, array{0: string, 1: string, 2: ?string, 3: float, 4: string, 5: array<string, string>}>
     */
    private const LISTINGS = [
        // Records, where grading and pressing carry the price.
        ['12-albums-lps', 'Miles Davis, Kind of Blue', 'Columbia', 85.00, 'GOOD', [
            'vinyl_size' => '12 inch', 'vinyl_speed' => '33 1/3 RPM', 'record_grading' => 'Very Good Plus (VG+)',
            'sleeve_grading' => 'Very Good (VG)', 'pressing_type' => 'Original pressing',
            'music_genre' => 'Jazz', 'release_decade' => '1950s', 'pressing_country' => 'UK',
            'vinyl_variant' => 'Standard black vinyl',
        ]],
        ['12-albums-lps', 'Pixies, Doolittle', '4AD', 28.00, 'EXCELLENT', [
            'vinyl_size' => '12 inch', 'vinyl_speed' => '33 1/3 RPM', 'record_grading' => 'Near Mint (NM)',
            'sleeve_grading' => 'Near Mint (NM)', 'pressing_type' => 'Reissue',
            'music_genre' => 'Indie & alternative', 'release_decade' => '1980s', 'pressing_country' => 'Europe',
            'vinyl_variant' => 'Standard black vinyl',
        ]],
        ['12-albums-lps', 'Aphex Twin, Selected Ambient Works', 'Warp', 42.00, 'EXCELLENT', [
            'vinyl_size' => '12 inch', 'vinyl_speed' => '33 1/3 RPM', 'record_grading' => 'Excellent (EX)',
            'pressing_type' => 'Repress', 'music_genre' => 'Electronic & dance', 'release_decade' => '1990s',
            'vinyl_variant' => 'Coloured vinyl',
        ]],
        ['7-singles', 'Northern Soul 7" bundle', 'Atlantic', 18.00, 'FAIR', [
            'vinyl_size' => '7 inch', 'vinyl_speed' => '45 RPM', 'record_grading' => 'Very Good (VG)',
            'sleeve_grading' => 'Generic or plain sleeve', 'music_genre' => 'Soul & Motown',
            'release_decade' => '1960s', 'pressing_country' => 'USA',
        ]],
        ['7-singles', 'The Specials, Gangsters', 'Two-Tone', 12.00, 'GOOD', [
            'vinyl_size' => '7 inch', 'vinyl_speed' => '45 RPM', 'record_grading' => 'Very Good Plus (VG+)',
            'music_genre' => 'Reggae, ska & dub', 'release_decade' => '1970s', 'pressing_country' => 'UK',
        ]],
        ['10-records', 'Blue Note 10" jazz reissue', 'Blue Note', 35.00, 'MINT', [
            'vinyl_size' => '10 inch', 'vinyl_speed' => '33 1/3 RPM', 'record_grading' => 'Still sealed',
            'pressing_type' => 'Reissue', 'music_genre' => 'Jazz', 'release_decade' => '2010s',
        ]],
        ['78-rpm-shellac', 'Wartime dance band 78s, box of twelve', null, 25.00, 'FAIR', [
            'vinyl_size' => '10 inch', 'vinyl_speed' => '78 RPM', 'record_grading' => 'Good (G)',
            'release_decade' => 'Pre-1950s',
        ]],
        ['album-cassettes', 'Fleetwood Mac, Rumours cassette', 'Warner Bros', 9.00, 'GOOD', [
            'cassette_tape_type' => 'Type I (normal)', 'record_grading' => 'Very Good (VG)',
            'music_genre' => 'Classic rock', 'release_decade' => '1970s',
        ]],
        // Brand left unset: the vinyl department's brand list is record
        // labels, and blank tape manufacturers are not on it.
        ['blank-cassettes', 'TDK SA90 blanks, sealed pack of five', null, 22.00, 'MINT', [
            'cassette_tape_type' => 'Type II (chrome)', 'blank_tape_length' => 'C90',
            'record_grading' => 'Still sealed',
        ]],

        // Turntables and hi-fi, where drive type and phono stage decide it.
        ['direct-drive-turntables', 'Technics SL-1210 MK2', 'Technics', 650.00, 'GOOD', [
            'drive_type' => 'Direct drive', 'turntable_speeds' => '33 and 45 RPM',
            'turntable_operation' => 'Manual', 'tonearm_type' => 'S-shaped',
            'cartridge_included' => 'Cartridge fitted, stylus worn',
            'built_in_phono_stage' => 'No, needs a phono input', 'usb_output' => 'No',
            'made_decade' => '1990s', 'working_order' => 'Fully working',
        ]],
        ['belt-drive-turntables', 'Rega Planar 2', 'Rega', 320.00, 'EXCELLENT', [
            'drive_type' => 'Belt drive', 'turntable_speeds' => '33 and 45 RPM',
            'turntable_operation' => 'Manual', 'tonearm_type' => 'Straight',
            'cartridge_included' => 'Cartridge and stylus fitted',
            'built_in_phono_stage' => 'No, needs a phono input', 'made_decade' => '2010s',
            'working_order' => 'Fully working',
        ]],
        ['all-in-one-record-players', 'Crosley suitcase player', 'Crosley', 45.00, 'FAIR', [
            'drive_type' => 'Belt drive', 'turntable_speeds' => '33, 45 and 78 RPM',
            'turntable_operation' => 'Fully automatic', 'built_in_phono_stage' => 'Yes, always on',
            'usb_output' => 'No', 'working_order' => 'Working with faults',
        ]],
        ['vintage-turntables', 'Garrard 401 idler deck, for restoration', 'Garrard', 480.00, 'FAIR', [
            'drive_type' => 'Idler wheel', 'turntable_speeds' => '33, 45 and 78 RPM',
            'turntable_operation' => 'Manual', 'made_decade' => 'Pre-1960s',
            'working_order' => 'For repair or spares',
        ]],
        ['cassette-decks', 'Nakamichi BX-100', 'Nakamichi', 240.00, 'GOOD', [
            'cassette_deck_type' => 'Three head', 'noise_reduction' => 'Dolby B',
            'made_decade' => '1980s', 'working_order' => 'Fully working',
        ]],
        ['bookshelf-speakers', 'Wharfedale Diamond 220', 'Wharfedale', 110.00, 'EXCELLENT', [
            'monitor_power_type' => 'Passive (needs an amp)', 'speaker_placement' => 'Bookshelf / standmount',
            'driver_size' => '5"', 'working_order' => 'Fully working',
        ]],
        ['integrated-amplifiers', 'Cambridge Audio AM10', 'Cambridge Audio', 130.00, 'GOOD', [
            'hifi_amp_technology' => 'Solid state', 'made_decade' => '2010s', 'working_order' => 'Fully working',
        ]],

        // Guitars, where shape and pickups are the first two questions.
        ['solid-body-electric-guitars', 'Fender Player Stratocaster', 'Fender', 560.00, 'EXCELLENT', [
            'guitar_body_shape' => 'Stratocaster style', 'guitar_strings_count' => '6 string',
            'pickup_configuration' => 'SSS (three single coils)', 'guitar_scale_length' => 'Fender scale (25.5")',
            'handedness' => 'Right-handed', 'finish_colour' => 'Sunburst', 'made_decade' => '2010s',
            'includes_case' => 'Gig bag included',
        ]],
        ['solid-body-electric-guitars', 'Epiphone Les Paul Standard', 'Epiphone', 340.00, 'GOOD', [
            'guitar_body_shape' => 'Les Paul style', 'guitar_strings_count' => '6 string',
            'pickup_configuration' => 'HH (two humbuckers)', 'guitar_scale_length' => 'Gibson scale (24.75")',
            'handedness' => 'Right-handed', 'finish_colour' => 'Sunburst', 'made_decade' => '2000s',
            'includes_case' => 'No case',
        ]],
        ['solid-body-electric-guitars', 'Left-handed Squier Telecaster', 'Squier', 220.00, 'GOOD', [
            'guitar_body_shape' => 'Telecaster style', 'guitar_strings_count' => '6 string',
            'pickup_configuration' => 'SS (two single coils)', 'handedness' => 'Left-handed',
            'finish_colour' => 'Natural', 'made_decade' => '2010s',
        ]],
        ['extended-range-guitars', 'Ibanez RG 7-string', 'Ibanez', 590.00, 'EXCELLENT', [
            'guitar_body_shape' => 'Superstrat', 'guitar_strings_count' => '7 string',
            'pickup_configuration' => 'HH (two humbuckers)', 'handedness' => 'Right-handed',
            'finish_colour' => 'Black', 'made_decade' => '2020s',
        ]],
        ['dreadnought-guitars', 'Yamaha FG800', 'Yamaha', 180.00, 'EXCELLENT', [
            'acoustic_body_shape' => 'Dreadnought', 'guitar_strings_count' => '6 string',
            'top_wood' => 'Solid spruce', 'acoustic_electronics' => 'No', 'handedness' => 'Right-handed',
            'instrument_size' => '4/4 (full size)',
        ]],

        // Amps and pedals, where valve or not and what powers it decide it.
        ['guitar-combo-amps', 'Fender Blues Junior IV', 'Fender', 430.00, 'EXCELLENT', [
            'amp_format' => 'Combo', 'amp_technology' => 'Valve / tube', 'amp_wattage' => '5W to 19W',
            'speaker_configuration' => '1x12"', 'made_decade' => '2010s', 'working_order' => 'Fully working',
        ]],
        ['guitar-amp-heads', 'Marshall JCM800 head', 'Marshall', 900.00, 'GOOD', [
            'amp_format' => 'Head', 'amp_technology' => 'Valve / tube', 'amp_wattage' => '50W to 99W',
            'speaker_configuration' => 'No speaker', 'made_decade' => '1980s', 'working_order' => 'Fully working',
        ]],
        ['practice-mini-amps', 'Boss Katana Mini', 'Boss', 55.00, 'GOOD', [
            'amp_format' => 'Combo', 'amp_technology' => 'Modelling / digital', 'amp_wattage' => 'Under 5W',
            'working_order' => 'Fully working',
        ]],
        ['delay-pedals', 'Boss DD-7 Digital Delay', 'Boss', 88.00, 'EXCELLENT', [
            'pedal_format' => 'Standard (BOSS size)', 'pedal_circuit' => 'Digital',
            'pedal_power_requirement' => '9V DC centre negative', 'bypass_type' => 'Buffered bypass',
            'working_order' => 'Fully working',
        ]],
        ['overdrive-distortion-pedals', 'Ibanez TS9 Tube Screamer', 'Ibanez', 95.00, 'GOOD', [
            'pedal_format' => 'Standard (BOSS size)', 'pedal_circuit' => 'Analogue',
            'pedal_power_requirement' => '9V DC centre negative', 'bypass_type' => 'Buffered bypass',
            'working_order' => 'Fully working',
        ]],
        ['fuzz-pedals', 'EHX Big Muff Pi, mini', 'Electro-Harmonix', 60.00, 'MINT', [
            'pedal_format' => 'Mini', 'pedal_circuit' => 'Analogue',
            'pedal_power_requirement' => '9V DC centre negative', 'bypass_type' => 'True bypass',
            'working_order' => 'Fully working',
        ]],

        // Studio.
        ['dynamic-microphones', 'Shure SM58', 'Shure', 68.00, 'GOOD', [
            'mic_type' => 'Dynamic', 'polar_pattern' => 'Cardioid', 'mic_connection' => 'XLR',
            'phantom_power' => 'No phantom needed', 'working_order' => 'Fully working',
        ]],
        ['condenser-microphones', 'Rode NT1-A', 'Rode', 120.00, 'EXCELLENT', [
            'mic_type' => 'Condenser (large diaphragm)', 'polar_pattern' => 'Cardioid',
            'mic_connection' => 'XLR', 'phantom_power' => 'Needs 48V phantom', 'working_order' => 'Fully working',
        ]],
        ['usb-audio-interfaces', 'Focusrite Scarlett 2i2 3rd gen', 'Focusrite', 95.00, 'EXCELLENT', [
            'interface_inputs' => '2 inputs', 'interface_connection' => 'USB-C',
            'working_order' => 'Fully working',
        ]],
        ['active-studio-monitors', 'Yamaha HS5 pair', 'Yamaha', 250.00, 'EXCELLENT', [
            'monitor_power_type' => 'Active (powered)', 'driver_size' => '5"', 'working_order' => 'Fully working',
        ]],

        // Keys.
        ['analogue-synthesisers', 'Roland Juno-106', 'Roland', 1450.00, 'GOOD', [
            'key_count' => '61 keys', 'key_action' => 'Synth action (unweighted)', 'synth_engine' => 'Analogue',
            'polyphony' => '5 to 8 voices', 'synth_format' => 'Keyboard', 'made_decade' => '1980s',
            'working_order' => 'Working with faults',
        ]],
        ['digital-synthesisers', 'Korg Minilogue XD', 'Korg', 480.00, 'EXCELLENT', [
            'key_count' => '37 keys', 'synth_engine' => 'Hybrid analogue-digital', 'polyphony' => '2 to 4 voices',
            'synth_format' => 'Keyboard', 'made_decade' => '2020s', 'working_order' => 'Fully working',
        ]],
        ['home-digital-pianos', 'Yamaha P-45', 'Yamaha', 320.00, 'GOOD', [
            'key_count' => '88 keys', 'key_action' => 'Weighted hammer action', 'working_order' => 'Fully working',
        ]],

        // Drums.
        ['rock-fusion-drum-kits', 'Pearl Export 5-piece', 'Pearl', 430.00, 'GOOD', [
            'kit_pieces' => '5 piece', 'shell_material' => 'Poplar', 'made_decade' => '2000s',
        ]],
        ['ride-cymbals', 'Zildjian A Custom 20" ride', 'Zildjian', 180.00, 'EXCELLENT', [
            'cymbal_size' => '20"', 'cymbal_alloy' => 'B20 bronze',
        ]],
        ['hi-hat-cymbals', 'Sabian HHX 14" hi-hats', 'Sabian', 210.00, 'EXCELLENT', [
            'cymbal_size' => '14"', 'cymbal_alloy' => 'B20 bronze',
        ]],

        // The mundane half, which is most of what a used market actually has.
        ['jack-to-jack-instrument-cables', 'Van Damme jack cable, 6m', 'Van Damme', 18.00, 'EXCELLENT', [
            'connector_a' => '1/4" jack (TS, mono)', 'connector_b' => '1/4" jack (TS, mono)',
            'cable_length' => '6m', 'cable_angle' => 'Straight both ends', 'cable_balance' => 'Unbalanced',
        ]],
        ['right-angle-jack-cables', 'Right-angle patch cable, 3m', 'Planet Waves', 12.00, 'GOOD', [
            'connector_a' => '1/4" jack (TS, mono)', 'connector_b' => '1/4" jack (TS, mono)',
            'cable_length' => '3m', 'cable_angle' => 'Right-angle one end', 'cable_balance' => 'Unbalanced',
        ]],
        ['xlr-cables', 'Neutrik XLR mic cable, 10m', 'Neutrik', 22.00, 'EXCELLENT', [
            'connector_a' => 'XLR male', 'connector_b' => 'XLR female',
            'cable_length' => '10m', 'cable_angle' => 'Straight both ends', 'cable_balance' => 'Balanced',
        ]],
        ['rca-phono-cables', 'Turntable RCA lead with ground wire', 'Chord', 25.00, 'EXCELLENT', [
            'connector_a' => 'RCA / phono', 'connector_b' => 'RCA / phono', 'cable_length' => '1.5m',
        ]],
        ['pedal-power-supplies', 'Truetone 1 Spot Pro CS7', 'Truetone', 130.00, 'EXCELLENT', [
            'psu_voltage' => '9V DC', 'psu_current' => '500mA to 1A', 'psu_outputs' => '5 to 8 outputs',
            'psu_isolated' => 'Isolated outputs', 'psu_polarity' => 'Centre negative',
            'working_order' => 'Fully working',
        ]],
        ['daisy-chain-cables', 'Five-way pedal daisy chain', 'Harley Benton Power', 8.00, 'GOOD', [
            'psu_voltage' => '9V DC', 'psu_outputs' => '5 to 8 outputs', 'psu_polarity' => 'Centre negative',
        ]],
        ['jack-plugs-sockets', 'Neutrik mono jack plugs, bag of ten', 'Neutrik', 14.00, 'MINT', []],
        ['guitar-straps', 'Leather guitar strap, wide', 'Levy\'s', 30.00, 'EXCELLENT', []],
        ['amp-footswitches', 'Two-button amp footswitch', 'Boss', 25.00, 'GOOD', [
            'footswitch_function' => 'Latching',
        ]],
        ['expression-pedals', 'Roland EV-5 expression pedal', 'Roland', 45.00, 'GOOD', [
            'footswitch_function' => 'Expression',
        ]],
        ['clip-on-tuners', 'Snark SN-5X clip-on tuner', 'Snark', 9.00, 'GOOD', []],
        ['guitar-cases-gig-bags', 'Hiscox hard case, dreadnought', 'Hiscox', 90.00, 'EXCELLENT', [
            'case_type' => 'Hard case',
        ]],
        ['microphone-stands', 'K&M boom mic stand', 'K&M', 35.00, 'GOOD', [
            'stand_style' => 'Boom arm',
        ]],
    ];

    public function run(): void
    {
        // One seller for all of it, clearly labelled. Spreading demo listings
        // across invented sellers would make the community endorsement
        // features look busier than they are, which is the kind of flattering
        // demo data that hides real gaps.
        $seller = User::firstOrCreate(
            ['email' => 'demo.seller@restrum.uk'],
            [
                'username' => 'demoseller',
                'password' => bcrypt('password'),
                'location' => 'Newcastle',
                'bio' => 'Demo listings, used to show the category tree and filters off.',
                'email_verified_at' => now(),
                // Payout ready, or none of these can be bought and the
                // checkout flow cannot be tried out locally. It is a fake
                // account id: real onboarding replaces it.
                'stripe_account_id' => 'acct_demo_seed',
                'stripe_transfers_enabled' => true,
                'stripe_payouts_enabled' => true,
                'stripe_synced_at' => now(),
            ],
        );

        foreach (self::LISTINGS as [$slug, $title, $brand, $price, $condition, $attributes]) {
            $category = Category::where('slug', $slug)->first();

            if ($category === null) {
                $this->command?->warn("Skipping '{$title}': no category '{$slug}'.");

                continue;
            }

            $listing = Listing::firstOrNew([
                'title' => $title,
                'seller_id' => $seller->id,
            ]);

            $listing->fill([
                'description' => "{$title}. Demo listing seeded to show the filters working.",
                'price' => $price,
                'location' => fake()->randomElement(['Newcastle', 'Leeds', 'Manchester', 'Bristol', 'Glasgow']),
                'category_id' => $category->id,
                'brand' => $brand,
                'condition' => $condition,
            ]);

            $listing->status = 'ACTIVE';
            $listing->save();

            $listing->syncAttributes($attributes);
        }

        $this->command?->info('Seeded '.count(self::LISTINGS).' demo listings across the catalog.');
    }
}
