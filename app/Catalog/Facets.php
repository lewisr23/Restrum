<?php

namespace App\Catalog;

/**
 * The filters that appear once a buyer has narrowed to part of the tree.
 *
 * A marketplace's filters are only as good as its worst one, so each of these
 * is defined for the smallest branch it actually makes sense in. "Number of
 * strings" belongs on guitars and nowhere else; "record grading" belongs on
 * records and is meaningless on a guitar lead. Declaring the branch on the
 * attribute rather than listing attributes on every category is what keeps
 * that honest: there is one place to look to see where a filter applies.
 *
 * `applies_to` holds category paths. An attribute applies to that category
 * and to everything under it, so 'guitars/electric-guitars' covers all nine
 * kinds of electric guitar without naming them.
 *
 * The options are closed lists for the same reason brands are (see Brands):
 * a facet whose values are free text is a facet with one listing behind each
 * value, which is a list of listings with extra steps.
 */
class Facets
{
    /**
     * @return array<string, array{label: string, applies_to: array<int, string>, options: array<int, string>, help?: string}>
     */
    public static function definitions(): array
    {
        return [
            /*
             * Instruments. Shape and configuration first, because that is how
             * players actually search: nobody wants "a guitar", they want a
             * Telecaster with a humbucker in the bridge.
             */

            'guitar_body_shape' => [
                'label' => 'Body shape',
                'applies_to' => ['guitars/electric-guitars'],
                'options' => [
                    'Stratocaster style', 'Telecaster style', 'Les Paul style', 'SG style',
                    'Superstrat', 'Offset (Jazzmaster, Jaguar)', 'Semi-hollow (335 style)',
                    'Hollow body / Archtop', 'Explorer style', 'Flying V style', 'Firebird style',
                    'PRS style', 'Mustang / Duo Sonic style', 'Single cut (other)', 'Double cut (other)',
                ],
            ],

            'acoustic_body_shape' => [
                'label' => 'Body shape',
                'applies_to' => ['guitars/acoustic-guitars'],
                'options' => [
                    'Dreadnought', 'Concert', 'Grand Auditorium', 'Grand Concert', 'Parlour',
                    'Jumbo', 'Classical', 'Travel / Mini', 'Resonator',
                ],
            ],

            'guitar_strings_count' => [
                'label' => 'Number of strings',
                'applies_to' => ['guitars/electric-guitars', 'guitars/acoustic-guitars'],
                'options' => ['6 string', '7 string', '8 string', '9 string', '12 string'],
            ],

            'pickup_configuration' => [
                'label' => 'Pickups',
                'applies_to' => ['guitars/electric-guitars'],
                'options' => [
                    'SSS (three single coils)', 'HSS', 'HSH', 'HH (two humbuckers)',
                    'SS (two single coils)', 'Two P90s', 'Single humbucker', 'Single P90',
                    'Piezo / acoustic', 'No pickups',
                ],
            ],

            'guitar_scale_length' => [
                'label' => 'Scale length',
                'applies_to' => ['guitars/electric-guitars'],
                'options' => [
                    'Short scale (24" and under)', 'Gibson scale (24.75")',
                    'Fender scale (25.5")', 'Multi-scale / fanned fret', 'Baritone (27"+)',
                ],
            ],

            'bass_strings_count' => [
                'label' => 'Number of strings',
                'applies_to' => ['bass-guitars'],
                'options' => ['4 string', '5 string', '6 string', '8 string', '12 string'],
            ],

            'bass_scale_length' => [
                'label' => 'Scale length',
                'applies_to' => ['bass-guitars/electric-bass-guitars'],
                'options' => [
                    'Short scale (30")', 'Medium scale (32")', 'Long scale (34")',
                    'Extra long scale (35"+)',
                ],
            ],

            'fretted_or_fretless' => [
                'label' => 'Fretted or fretless',
                'applies_to' => ['bass-guitars/electric-bass-guitars', 'bass-guitars/acoustic-bass-guitars'],
                'options' => ['Fretted', 'Fretless', 'Lined fretless'],
            ],

            'handedness' => [
                'label' => 'Left or right handed',
                'applies_to' => [
                    'guitars/electric-guitars', 'guitars/acoustic-guitars', 'bass-guitars',
                    'folk-traditional/ukuleles', 'folk-traditional/banjos', 'folk-traditional/mandolins',
                ],
                'options' => ['Right-handed', 'Left-handed'],
            ],

            'top_wood' => [
                'label' => 'Top',
                'applies_to' => ['guitars/acoustic-guitars'],
                'options' => [
                    'Solid spruce', 'Solid cedar', 'Solid mahogany', 'Solid koa', 'Solid maple',
                    'Laminate spruce', 'Laminate mahogany', 'Other laminate',
                ],
            ],

            'acoustic_electronics' => [
                'label' => 'Built-in pickup',
                'applies_to' => ['guitars/acoustic-guitars', 'folk-traditional/ukuleles', 'folk-traditional/mandolins'],
                'options' => ['Yes, with preamp', 'Yes, passive pickup', 'No'],
            ],

            'finish_colour' => [
                'label' => 'Finish',
                'applies_to' => [
                    'guitars/electric-guitars', 'guitars/acoustic-guitars', 'bass-guitars/electric-bass-guitars',
                ],
                'options' => [
                    'Sunburst', 'Black', 'White', 'Natural', 'Red', 'Blue', 'Green', 'Yellow',
                    'Gold', 'Silver / Chrome', 'Sparkle', 'Relic / aged', 'Multi-colour',
                ],
            ],

            /*
             * Amplification. Valve or not is the first question anyone asks
             * about a used amp, and wattage is the second.
             */

            'amp_format' => [
                'label' => 'Format',
                'applies_to' => ['guitars/guitar-amplifiers', 'bass-guitars/bass-amplifiers'],
                'options' => ['Combo', 'Head', 'Speaker cabinet', 'Rack', 'Floor / pedal amp'],
            ],

            'amp_technology' => [
                'label' => 'Amp type',
                'applies_to' => ['guitars/guitar-amplifiers', 'bass-guitars/bass-amplifiers'],
                'options' => ['Valve / tube', 'Solid state', 'Modelling / digital', 'Hybrid'],
            ],

            'amp_wattage' => [
                'label' => 'Power',
                'applies_to' => ['guitars/guitar-amplifiers', 'bass-guitars/bass-amplifiers'],
                'options' => [
                    'Under 5W', '5W to 19W', '20W to 49W', '50W to 99W', '100W to 199W', '200W and over',
                ],
            ],

            'speaker_configuration' => [
                'label' => 'Speakers',
                'applies_to' => ['guitars/guitar-amplifiers', 'bass-guitars/bass-amplifiers'],
                'options' => [
                    '1x8"', '1x10"', '1x12"', '1x15"', '2x10"', '2x12"', '4x10"', '4x12"',
                    '8x10"', 'Other', 'No speaker',
                ],
            ],

            /*
             * Pedals. Power is the mundane detail that decides whether a pedal
             * fits on the board you already own.
             */

            'pedal_format' => [
                'label' => 'Pedal size',
                'applies_to' => ['guitars/effects-pedals', 'bass-guitars/bass-effects-pedals'],
                'options' => [
                    'Mini', 'Standard (BOSS size)', 'Large / dual', 'Multi-effects floorboard',
                    'Rack unit', 'Desktop', 'Strip / preamp',
                ],
            ],

            'pedal_circuit' => [
                'label' => 'Circuit',
                'applies_to' => ['guitars/effects-pedals', 'bass-guitars/bass-effects-pedals'],
                'options' => ['Analogue', 'Digital', 'Valve', 'Hybrid'],
            ],

            'pedal_power_requirement' => [
                'label' => 'Power needed',
                'applies_to' => ['guitars/effects-pedals', 'bass-guitars/bass-effects-pedals'],
                'options' => [
                    '9V DC centre negative', '9V DC centre positive', '12V DC', '18V DC',
                    '24V DC', 'Battery only', 'USB powered', 'Mains IEC',
                ],
            ],

            'bypass_type' => [
                'label' => 'Bypass',
                'applies_to' => ['guitars/effects-pedals', 'bass-guitars/bass-effects-pedals'],
                'options' => ['True bypass', 'Buffered bypass', 'Switchable', 'Not stated'],
            ],

            /*
             * Drums.
             */

            'kit_pieces' => [
                'label' => 'Number of pieces',
                'applies_to' => ['drums-percussion/acoustic-drum-kits', 'drums-percussion/electronic-drums'],
                'options' => ['3 piece', '4 piece', '5 piece', '6 piece', '7 piece or more'],
            ],

            'shell_material' => [
                'label' => 'Shell material',
                'applies_to' => ['drums-percussion/acoustic-drum-kits', 'drums-percussion/snare-drums'],
                'options' => [
                    'Maple', 'Birch', 'Poplar', 'Mahogany', 'Beech', 'Oak', 'Acrylic',
                    'Steel', 'Brass', 'Bronze', 'Aluminium', 'Mixed woods',
                ],
            ],

            'snare_size' => [
                'label' => 'Snare size',
                'applies_to' => ['drums-percussion/snare-drums'],
                'options' => [
                    '10x5"', '12x5"', '13x6.5"', '14x4" (piccolo)', '14x5"', '14x5.5"',
                    '14x6.5"', '14x8"', 'Other',
                ],
            ],

            'cymbal_size' => [
                'label' => 'Size',
                'applies_to' => ['drums-percussion/cymbals'],
                'options' => [
                    '8"', '10"', '12"', '13"', '14"', '15"', '16"', '17"', '18"',
                    '19"', '20"', '21"', '22"', '24" and over',
                ],
            ],

            'cymbal_alloy' => [
                'label' => 'Alloy',
                'applies_to' => ['drums-percussion/cymbals'],
                'options' => ['B20 bronze', 'B12 bronze', 'B10 bronze', 'B8 bronze', 'Brass', 'Nickel silver'],
            ],

            'electronic_pad_type' => [
                'label' => 'Pads',
                'applies_to' => ['drums-percussion/electronic-drums'],
                'options' => ['Mesh heads', 'Rubber pads', 'Mixed mesh and rubber', 'Silicone'],
            ],

            /*
             * Keys and synths.
             */

            'key_count' => [
                'label' => 'Keys',
                'applies_to' => [
                    'keys-synths/synthesisers', 'keys-synths/digital-pianos', 'keys-synths/keyboards',
                    'keys-synths/midi-controllers', 'keys-synths/organs',
                ],
                'options' => [
                    '25 keys', '32 keys', '37 keys', '44 keys', '49 keys', '61 keys',
                    '73 keys', '76 keys', '88 keys', 'No keyboard (desktop or module)',
                ],
            ],

            'key_action' => [
                'label' => 'Key action',
                'applies_to' => [
                    'keys-synths/synthesisers', 'keys-synths/digital-pianos',
                    'keys-synths/keyboards', 'keys-synths/midi-controllers',
                ],
                'options' => [
                    'Weighted hammer action', 'Semi-weighted', 'Synth action (unweighted)',
                    'Waterfall', 'Wooden keys',
                ],
            ],

            'synth_engine' => [
                'label' => 'Sound engine',
                'applies_to' => ['keys-synths/synthesisers', 'keys-synths/modular-eurorack', 'keys-synths/grooveboxes-samplers'],
                'options' => [
                    'Analogue', 'Virtual analogue', 'Digital / PCM', 'FM', 'Wavetable',
                    'Sample-based', 'Granular', 'Physical modelling', 'Hybrid analogue-digital',
                ],
            ],

            'polyphony' => [
                'label' => 'Polyphony',
                'applies_to' => ['keys-synths/synthesisers'],
                'options' => [
                    'Monophonic', 'Paraphonic', '2 to 4 voices', '5 to 8 voices',
                    '9 to 16 voices', '17 to 32 voices', 'More than 32 voices',
                ],
            ],

            'synth_format' => [
                'label' => 'Format',
                'applies_to' => ['keys-synths/synthesisers', 'keys-synths/grooveboxes-samplers'],
                'options' => ['Keyboard', 'Desktop', 'Rack mount', 'Eurorack module', 'Pedal', 'Semi-modular'],
            ],

            'eurorack_width' => [
                'label' => 'Module width',
                'applies_to' => ['keys-synths/modular-eurorack'],
                'options' => ['Up to 4HP', '5 to 8HP', '9 to 12HP', '13 to 20HP', 'Over 20HP', 'Case or power'],
            ],

            /*
             * Studio. The questions a buyer has to answer before a thing can
             * join their existing setup: how does it connect, and how many.
             */

            'mic_type' => [
                'label' => 'Microphone type',
                'applies_to' => ['studio-recording/microphones'],
                'options' => [
                    'Dynamic', 'Condenser (large diaphragm)', 'Condenser (small diaphragm)',
                    'Ribbon', 'Valve / tube', 'USB', 'Boundary / PZM', 'Shotgun',
                ],
            ],

            'polar_pattern' => [
                'label' => 'Polar pattern',
                'applies_to' => ['studio-recording/microphones'],
                'options' => [
                    'Cardioid', 'Supercardioid', 'Hypercardioid', 'Omnidirectional',
                    'Figure of 8', 'Multi-pattern', 'Lobar / shotgun',
                ],
            ],

            'mic_connection' => [
                'label' => 'Connection',
                'applies_to' => ['studio-recording/microphones'],
                'options' => ['XLR', 'USB', 'XLR and USB', '3.5mm', 'Wireless capsule'],
            ],

            'phantom_power' => [
                'label' => 'Phantom power',
                'applies_to' => ['studio-recording/microphones'],
                'options' => ['Needs 48V phantom', 'No phantom needed'],
            ],

            'interface_inputs' => [
                'label' => 'Inputs',
                'applies_to' => ['studio-recording/audio-interfaces'],
                'options' => ['1 input', '2 inputs', '4 inputs', '6 to 8 inputs', '10 to 16 inputs', 'More than 16'],
            ],

            'interface_connection' => [
                'label' => 'Computer connection',
                'applies_to' => ['studio-recording/audio-interfaces'],
                'options' => [
                    'USB-C', 'USB-B', 'USB-A', 'Thunderbolt', 'PCIe', 'Ethernet / Dante',
                    'FireWire', 'Lightning / iOS',
                ],
            ],

            'monitor_power_type' => [
                'label' => 'Active or passive',
                'applies_to' => [
                    'studio-recording/studio-monitors', 'hi-fi-home-audio/hi-fi-speakers',
                    'live-sound-pa/pa-speakers',
                ],
                'options' => ['Active (powered)', 'Passive (needs an amp)'],
            ],

            'driver_size' => [
                'label' => 'Driver size',
                'applies_to' => [
                    'studio-recording/studio-monitors', 'live-sound-pa/pa-speakers',
                    'hi-fi-home-audio/hi-fi-speakers',
                ],
                'options' => ['3"', '4"', '5"', '6.5"', '7"', '8"', '10"', '12"', '15"', '18"'],
            ],

            'headphone_design' => [
                'label' => 'Design',
                'applies_to' => ['studio-recording/studio-headphones', 'hi-fi-home-audio/hi-fi-headphones', 'dj-equipment/dj-headphones'],
                'options' => ['Closed-back', 'Open-back', 'Semi-open', 'In-ear', 'On-ear'],
            ],

            'rack_units' => [
                'label' => 'Rack size',
                'applies_to' => ['studio-recording/outboard-processing', 'live-sound-pa/power-amplifiers'],
                'options' => ['Not rack mount', '1U', '2U', '3U', '4U or more', '500 series module'],
            ],

            'mixer_channels' => [
                'label' => 'Channels',
                'applies_to' => ['studio-recording/mixing-desks', 'live-sound-pa/live-mixers', 'dj-equipment/dj-mixers'],
                'options' => [
                    '2 channels', '3 to 4 channels', '5 to 8 channels', '9 to 16 channels',
                    '17 to 24 channels', '25 to 32 channels', 'More than 32 channels',
                ],
            ],

            'mixer_type' => [
                'label' => 'Mixer type',
                'applies_to' => ['studio-recording/mixing-desks', 'live-sound-pa/live-mixers'],
                'options' => ['Analogue', 'Digital', 'Powered', 'Rotary'],
            ],

            'pa_power_output' => [
                'label' => 'Power output',
                'applies_to' => ['live-sound-pa/pa-speakers', 'live-sound-pa/power-amplifiers'],
                'options' => ['Under 300W', '300W to 600W', '600W to 1000W', '1000W to 2000W', 'Over 2000W'],
            ],

            /*
             * Turntables. The facets a used record deck is actually judged on,
             * and the ones a Crosley buyer and a Technics buyer both need.
             */

            'drive_type' => [
                'label' => 'Drive type',
                'applies_to' => ['hi-fi-home-audio/record-players-turntables', 'dj-equipment/dj-turntables'],
                'options' => ['Belt drive', 'Direct drive', 'Idler wheel'],
            ],

            'turntable_speeds' => [
                'label' => 'Speeds',
                'applies_to' => ['hi-fi-home-audio/record-players-turntables', 'dj-equipment/dj-turntables'],
                'options' => [
                    '33 and 45 RPM', '33, 45 and 78 RPM', '33 RPM only', '45 RPM only',
                ],
            ],

            'turntable_operation' => [
                'label' => 'Operation',
                'applies_to' => ['hi-fi-home-audio/record-players-turntables'],
                'options' => ['Fully automatic', 'Semi-automatic', 'Manual'],
            ],

            'tonearm_type' => [
                'label' => 'Tonearm',
                'applies_to' => ['hi-fi-home-audio/record-players-turntables', 'dj-equipment/dj-turntables'],
                'options' => ['Straight', 'S-shaped', 'J-shaped', 'Tangential / linear tracking', 'Unipivot'],
            ],

            'cartridge_included' => [
                'label' => 'Cartridge',
                'applies_to' => ['hi-fi-home-audio/record-players-turntables', 'dj-equipment/dj-turntables'],
                'options' => ['Cartridge and stylus fitted', 'Cartridge fitted, stylus worn', 'No cartridge'],
            ],

            'built_in_phono_stage' => [
                'label' => 'Built-in phono stage',
                'applies_to' => ['hi-fi-home-audio/record-players-turntables'],
                'options' => ['Yes, switchable', 'Yes, always on', 'No, needs a phono input'],
            ],

            'usb_output' => [
                'label' => 'USB output',
                'applies_to' => ['hi-fi-home-audio/record-players-turntables', 'dj-equipment/dj-turntables'],
                'options' => ['Yes, records to computer', 'No'],
            ],

            'cartridge_type' => [
                'label' => 'Cartridge type',
                'applies_to' => ['hi-fi-home-audio/turntable-parts-care', 'dj-equipment/cartridges-styli'],
                'options' => ['Moving magnet (MM)', 'Moving coil (MC)', 'Moving iron', 'Ceramic', 'Stylus only'],
            ],

            /*
             * The rest of the hi-fi separates.
             */

            'hifi_amp_technology' => [
                'label' => 'Amplifier type',
                'applies_to' => ['hi-fi-home-audio/hi-fi-amplifiers'],
                'options' => ['Valve', 'Solid state', 'Class D', 'Hybrid'],
            ],

            'speaker_placement' => [
                'label' => 'Placement',
                'applies_to' => ['hi-fi-home-audio/hi-fi-speakers'],
                'options' => ['Bookshelf / standmount', 'Floorstanding', 'Wall or ceiling mount', 'Subwoofer', 'Desktop'],
            ],

            'cassette_deck_type' => [
                'label' => 'Deck type',
                'applies_to' => ['hi-fi-home-audio/tape-cassette'],
                'options' => ['Single deck', 'Twin deck', 'Three head', 'Portable', 'Radio cassette'],
            ],

            'noise_reduction' => [
                'label' => 'Noise reduction',
                'applies_to' => ['hi-fi-home-audio/tape-cassette'],
                'options' => ['Dolby B', 'Dolby C', 'Dolby S', 'Dolby HX Pro', 'dbx', 'None'],
            ],

            /*
             * Recorded music. Records are bought on grading and pressing far
             * more than on anything else, so those come first, and the
             * grading scale is the Goldmine one that every record shop and
             * every Discogs listing already uses.
             */

            'vinyl_size' => [
                'label' => 'Record size',
                'applies_to' => ['vinyl-tapes-cds/vinyl-records'],
                'options' => ['7 inch', '10 inch', '12 inch'],
            ],

            'vinyl_speed' => [
                'label' => 'Speed',
                'applies_to' => ['vinyl-tapes-cds/vinyl-records'],
                'options' => ['33 1/3 RPM', '45 RPM', '78 RPM', '16 RPM'],
            ],

            'record_grading' => [
                'label' => 'Record condition',
                'applies_to' => ['vinyl-tapes-cds/vinyl-records', 'vinyl-tapes-cds/cassettes', 'vinyl-tapes-cds/compact-discs', 'vinyl-tapes-cds/other-formats'],
                'options' => [
                    'Still sealed', 'Mint (M)', 'Near Mint (NM)', 'Excellent (EX)',
                    'Very Good Plus (VG+)', 'Very Good (VG)', 'Good (G)', 'Fair (F)', 'Poor (P)',
                ],
                'help' => 'The Goldmine grading standard, the same scale record shops and Discogs use.',
            ],

            'sleeve_grading' => [
                'label' => 'Sleeve condition',
                'applies_to' => ['vinyl-tapes-cds/vinyl-records'],
                'options' => [
                    'Mint (M)', 'Near Mint (NM)', 'Excellent (EX)', 'Very Good Plus (VG+)',
                    'Very Good (VG)', 'Good (G)', 'Fair (F)', 'Poor (P)',
                    'Generic or plain sleeve', 'No sleeve',
                ],
            ],

            'vinyl_variant' => [
                'label' => 'Pressing variant',
                'applies_to' => ['vinyl-tapes-cds/vinyl-records'],
                'options' => [
                    'Standard black vinyl', 'Coloured vinyl', 'Splatter', 'Marbled',
                    'Clear', 'Picture disc', 'Etched', 'Half-speed master', 'Audiophile pressing',
                ],
            ],

            'pressing_type' => [
                'label' => 'Pressing',
                'applies_to' => ['vinyl-tapes-cds/vinyl-records', 'vinyl-tapes-cds/cassettes', 'vinyl-tapes-cds/compact-discs'],
                'options' => [
                    'Original pressing', 'Reissue', 'Repress', 'Limited edition',
                    'Record Store Day', 'Promo', 'Test pressing', 'Unofficial / bootleg',
                ],
            ],

            'pressing_country' => [
                'label' => 'Pressed in',
                'applies_to' => ['vinyl-tapes-cds/vinyl-records'],
                'options' => ['UK', 'Europe', 'USA', 'Japan', 'Canada', 'Australia', 'Other'],
            ],

            'cassette_tape_type' => [
                'label' => 'Tape type',
                'applies_to' => ['vinyl-tapes-cds/cassettes'],
                'options' => [
                    'Type I (normal)', 'Type II (chrome)', 'Type III (ferrichrome)', 'Type IV (metal)',
                ],
            ],

            'blank_tape_length' => [
                'label' => 'Tape length',
                'applies_to' => ['vinyl-tapes-cds/cassettes'],
                'options' => ['C46', 'C60', 'C90', 'C100', 'C120', 'Other'],
            ],

            'music_genre' => [
                'label' => 'Genre',
                'applies_to' => ['vinyl-tapes-cds', 'sheet-music-tuition/sheet-music'],
                'options' => [
                    'Rock', 'Classic rock', 'Indie & alternative', 'Punk', 'Metal', 'Pop',
                    'Jazz', 'Blues', 'Soul & Motown', 'Funk & disco', 'Reggae, ska & dub',
                    'Hip-hop & rap', 'Electronic & dance', 'House & techno', 'Drum & bass',
                    'Ambient & experimental', 'Classical', 'Opera', 'Folk & acoustic',
                    'Country & Americana', 'World & Latin', 'Soundtracks & scores',
                    'Spoken word & comedy', 'Children\'s', 'Compilations',
                ],
            ],

            'release_decade' => [
                'label' => 'Decade',
                'applies_to' => ['vinyl-tapes-cds'],
                'options' => [
                    'Pre-1950s', '1950s', '1960s', '1970s', '1980s', '1990s',
                    '2000s', '2010s', '2020s',
                ],
            ],

            /*
             * The mundane half of a music shop, and the half a used
             * marketplace is actually full of. A lead is only useful if it
             * has the right plugs on the right ends and reaches far enough,
             * so those are exactly the filters.
             */

            'connector_a' => [
                'label' => 'Connector (end A)',
                'applies_to' => ['cables-power-accessories/instrument-speaker-cables', 'cables-power-accessories/microphone-line-cables', 'cables-power-accessories/digital-data-cables', 'cables-power-accessories/hi-fi-home-cables'],
                'options' => self::CONNECTORS,
            ],

            'connector_b' => [
                'label' => 'Connector (end B)',
                'applies_to' => ['cables-power-accessories/instrument-speaker-cables', 'cables-power-accessories/microphone-line-cables', 'cables-power-accessories/digital-data-cables', 'cables-power-accessories/hi-fi-home-cables'],
                'options' => self::CONNECTORS,
            ],

            'cable_length' => [
                'label' => 'Length',
                'applies_to' => ['cables-power-accessories/instrument-speaker-cables', 'cables-power-accessories/microphone-line-cables', 'cables-power-accessories/digital-data-cables', 'cables-power-accessories/hi-fi-home-cables'],
                'options' => [
                    'Under 1m', '1m', '1.5m', '2m', '3m', '4.5m', '6m', '9m', '10m', 'Over 10m',
                ],
            ],

            'cable_angle' => [
                'label' => 'Plug angle',
                'applies_to' => ['cables-power-accessories/instrument-speaker-cables', 'cables-power-accessories/microphone-line-cables'],
                'options' => ['Straight both ends', 'Right-angle one end', 'Right-angle both ends', 'Coiled'],
            ],

            'cable_balance' => [
                'label' => 'Balanced',
                'applies_to' => ['cables-power-accessories/instrument-speaker-cables', 'cables-power-accessories/microphone-line-cables'],
                'options' => ['Balanced', 'Unbalanced'],
            ],

            'psu_voltage' => [
                'label' => 'Voltage',
                'applies_to' => ['cables-power-accessories/power'],
                'options' => [
                    '9V DC', '12V DC', '15V DC', '18V DC', '24V DC', 'Multi-voltage', 'Mains AC',
                ],
            ],

            'psu_current' => [
                'label' => 'Current',
                'applies_to' => ['cables-power-accessories/power'],
                'options' => ['Up to 200mA', '200 to 500mA', '500mA to 1A', '1A to 2A', 'Over 2A'],
            ],

            'psu_outputs' => [
                'label' => 'Outputs',
                'applies_to' => ['cables-power-accessories/power'],
                'options' => ['1 output', '2 to 4 outputs', '5 to 8 outputs', '9 or more outputs'],
            ],

            'psu_isolated' => [
                'label' => 'Isolated outputs',
                'applies_to' => ['cables-power-accessories/power'],
                'options' => ['Isolated outputs', 'Not isolated', 'Some isolated'],
            ],

            'psu_polarity' => [
                'label' => 'Polarity',
                'applies_to' => ['cables-power-accessories/power'],
                'options' => ['Centre negative', 'Centre positive', 'Switchable', 'Not applicable'],
            ],

            'case_type' => [
                'label' => 'Case type',
                'applies_to' => ['cables-power-accessories/cases-bags'],
                'options' => [
                    'Hard case', 'Flight case', 'Padded gig bag', 'Lightweight gig bag',
                    'Semi-rigid case', 'Soft dust cover',
                ],
            ],

            'stand_style' => [
                'label' => 'Stand type',
                'applies_to' => ['cables-power-accessories/stands-mounts'],
                'options' => [
                    'Tripod', 'A-frame', 'X-frame', 'Z-frame', 'Boom arm', 'Straight',
                    'Wall hanger', 'Multi-instrument rack', 'Desktop',
                ],
            ],

            'footswitch_function' => [
                'label' => 'Switch type',
                'applies_to' => ['cables-power-accessories/footswitches-foot-pedals'],
                'options' => [
                    'Latching', 'Momentary', 'Expression', 'Volume', 'Sustain',
                    'MIDI', 'Dual function', 'Switchable polarity',
                ],
            ],

            /*
             * Wind, brass and orchestral. Keyed instruments are sized and
             * pitched rather than shaped, so these are the equivalent of body
             * shape and string count elsewhere.
             */

            'instrument_key' => [
                'label' => 'Key',
                'applies_to' => ['wind-brass'],
                'options' => ['Bb', 'Eb', 'C', 'F', 'A', 'D', 'G', 'Low A', 'Other'],
            ],

            'instrument_finish' => [
                'label' => 'Finish',
                'applies_to' => ['wind-brass'],
                'options' => [
                    'Gold lacquer', 'Clear lacquer', 'Silver plate', 'Gold plate',
                    'Nickel plate', 'Black nickel', 'Raw / unlacquered brass', 'Vintage matte',
                ],
            ],

            'reed_strength' => [
                'label' => 'Reed strength',
                'applies_to' => ['wind-brass/wind-brass-accessories'],
                'options' => ['1', '1.5', '2', '2.5', '3', '3.5', '4', '4.5', '5'],
            ],

            'instrument_size' => [
                'label' => 'Instrument size',
                'applies_to' => ['orchestral-strings', 'guitars/acoustic-guitars'],
                'options' => ['4/4 (full size)', '7/8', '3/4', '1/2', '1/4', '1/8', '1/16'],
            ],

            'bow_material' => [
                'label' => 'Bow material',
                'applies_to' => ['orchestral-strings/bows'],
                'options' => ['Brazilwood', 'Pernambuco', 'Carbon fibre', 'Fibreglass', 'Composite'],
            ],

            /*
             * Cross-cutting, declared last so they sit at the bottom of the
             * filter panel under the things specific to what you are looking
             * at.
             */

            'made_decade' => [
                'label' => 'Era',
                'applies_to' => [
                    'guitars', 'bass-guitars', 'drums-percussion', 'keys-synths',
                    'hi-fi-home-audio', 'studio-recording', 'dj-equipment', 'wind-brass',
                    'orchestral-strings', 'folk-traditional',
                ],
                'options' => [
                    'Pre-1960s', '1960s', '1970s', '1980s', '1990s', '2000s',
                    '2010s', '2020s', 'Not known',
                ],
                'help' => 'Roughly when it was made, not when it was bought.',
            ],

            'includes_case' => [
                'label' => 'Case included',
                'applies_to' => [
                    'guitars/electric-guitars', 'guitars/acoustic-guitars', 'bass-guitars',
                    'wind-brass', 'orchestral-strings', 'folk-traditional',
                ],
                'options' => ['Hard case included', 'Gig bag included', 'No case'],
            ],

            'working_order' => [
                'label' => 'Working order',
                'applies_to' => [
                    'guitars/guitar-amplifiers', 'guitars/effects-pedals', 'bass-guitars/bass-amplifiers',
                    'keys-synths', 'studio-recording', 'live-sound-pa', 'dj-equipment',
                    'hi-fi-home-audio', 'drums-percussion/electronic-drums',
                    'cables-power-accessories/power',
                ],
                'options' => [
                    'Fully working', 'Working with faults', 'For repair or spares', 'Untested',
                ],
                'help' => 'Be honest here. It is the single thing buyers of used electronics ask about.',
            ],
        ];
    }

    /**
     * Shared between both ends of a cable, so the two lists cannot drift
     * apart and a search for "XLR to jack" matches from either direction.
     */
    private const CONNECTORS = [
        '1/4" jack (TS, mono)', '1/4" jack (TRS, stereo)', 'XLR male', 'XLR female',
        'RCA / phono', '3.5mm mini jack', 'Speakon', 'Banana plug', 'Bare wire',
        'MIDI 5-pin DIN', 'USB-A', 'USB-B', 'USB-C', 'Optical TOSLINK', 'BNC',
        'IEC mains', 'DC barrel', 'Multipin / other',
    ];

    /**
     * The attributes that apply to a category, in declaration order.
     *
     * A category inherits everything declared for any ancestor, which is what
     * lets "Era" be declared once on Guitars and appear on all forty leaves
     * beneath it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forCategoryPath(string $path): array
    {
        $applicable = [];

        foreach (self::definitions() as $name => $definition) {
            foreach ($definition['applies_to'] as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                    $applicable[$name] = $definition;
                    break;
                }
            }
        }

        return $applicable;
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Whether a value is one this attribute actually offers.
     *
     * Checked on save rather than only in the form, because the form is not
     * where the request comes from.
     */
    public static function isValidValue(string $name, string $value): bool
    {
        $definition = self::definitions()[$name] ?? null;

        return $definition !== null && in_array($value, $definition['options'], true);
    }
}
