<?php

namespace App\Catalog;

/**
 * The category tree, as data.
 *
 * Written as nested names rather than as rows because this is a document
 * somebody edits, not a table somebody queries: the shape of the tree is the
 * information, and a list of parent ids hides it. CatalogSync turns this into
 * the `categories` table, slugs and all, and `php artisan catalog:sync`
 * reapplies it after an edit here.
 *
 * A leaf is a string. A branch is a key with an array under it. Nothing else
 * is allowed, which is what keeps a 400 node tree readable.
 *
 * Two rules worth knowing before editing:
 *
 * 1. Only leaves can be listed in. A seller picking "Guitars" tells a buyer
 *    almost nothing, and the whole point of going this deep is that the
 *    filters mean something. Branches are for browsing.
 *
 * 2. Names become slugs, so renaming a category changes its URL. Duplicate
 *    names in different branches are fine and expected ("Power Amplifiers"
 *    appears under both live sound and hi-fi); CatalogSync disambiguates the
 *    second one with its parent's slug.
 */
class Taxonomy
{
    /** @return array<string, mixed> */
    public static function tree(): array
    {
        return [
            'Guitars' => [
                'Electric Guitars' => [
                    'Solid Body Electric Guitars',
                    'Semi-Hollow Electric Guitars',
                    'Hollow Body Electric Guitars',
                    'Archtop Guitars',
                    'Baritone Guitars',
                    'Extended Range Guitars',
                    'Travel & Mini Electric Guitars',
                    'Left-Handed Electric Guitars',
                    'Electric Guitar Packs',
                ],
                'Acoustic Guitars' => [
                    'Dreadnought Guitars',
                    'Concert & Grand Auditorium Guitars',
                    'Parlour Guitars',
                    'Jumbo Guitars',
                    '12-String Acoustic Guitars',
                    'Classical & Nylon String Guitars',
                    'Electro-Acoustic Guitars',
                    'Resonator Guitars',
                    'Travel & Mini Acoustic Guitars',
                    'Left-Handed Acoustic Guitars',
                ],
                'Guitar Amplifiers' => [
                    'Guitar Combo Amps',
                    'Guitar Amp Heads',
                    'Guitar Speaker Cabinets',
                    'Practice & Mini Amps',
                    'Acoustic Guitar Amps',
                    'Amp Attenuators & Load Boxes',
                    'Valve Amp Spares & Tubes',
                ],
                'Effects Pedals' => [
                    'Overdrive & Distortion Pedals',
                    'Fuzz Pedals',
                    'Delay Pedals',
                    'Reverb Pedals',
                    'Chorus & Modulation Pedals',
                    'Phaser & Flanger Pedals',
                    'Tremolo & Vibrato Pedals',
                    'Compressor & Sustain Pedals',
                    'EQ & Boost Pedals',
                    'Wah & Filter Pedals',
                    'Pitch & Octave Pedals',
                    'Harmoniser & Whammy Pedals',
                    'Looper Pedals',
                    'Noise Gates',
                    'Multi-Effects Units',
                    'Amp & Cab Simulators',
                    'Pedalboards',
                ],
                'Guitar Parts' => [
                    'Guitar Pickups',
                    'Machine Heads & Tuners',
                    'Bridges & Tremolo Systems',
                    'Guitar Necks',
                    'Guitar Bodies',
                    'Scratchplates & Pickguards',
                    'Pots, Switches & Jack Sockets',
                    'Nuts, Saddles & Fretwire',
                    'Knobs & Control Plates',
                    'Strap Buttons & Strap Locks',
                ],
                'Guitar Strings & Consumables' => [
                    'Electric Guitar Strings',
                    'Acoustic Guitar Strings',
                    'Classical Guitar Strings',
                    'Plectrums & Picks',
                    'Guitar Slides',
                    'Capos',
                    'Guitar Straps',
                    'String Winders & Cutters',
                ],
            ],

            'Bass Guitars' => [
                'Electric Bass Guitars' => [
                    '4-String Bass Guitars',
                    '5-String Bass Guitars',
                    '6-String Bass Guitars',
                    'Short Scale Bass Guitars',
                    'Fretless Bass Guitars',
                    'Left-Handed Bass Guitars',
                    'Bass Guitar Packs',
                ],
                'Acoustic Bass Guitars',
                'Double Bass & Upright Bass',
                'Bass Amplifiers' => [
                    'Bass Combo Amps',
                    'Bass Amp Heads',
                    'Bass Speaker Cabinets',
                    'Practice Bass Amps',
                ],
                'Bass Effects Pedals' => [
                    'Bass Overdrive & Distortion',
                    'Bass Compressors',
                    'Bass Preamps & DI Pedals',
                    'Bass Octave & Synth Pedals',
                    'Bass Multi-Effects',
                ],
                'Bass Parts & Accessories' => [
                    'Bass Pickups',
                    'Bass Bridges',
                    'Bass Necks',
                    'Bass Guitar Strings',
                    'Bass Straps',
                ],
            ],

            'Drums & Percussion' => [
                'Acoustic Drum Kits' => [
                    'Rock & Fusion Drum Kits',
                    'Jazz & Bop Drum Kits',
                    'Shell Packs',
                    'Junior & Kids Drum Kits',
                    'Vintage Drum Kits',
                ],
                'Electronic Drums' => [
                    'Electronic Drum Kits',
                    'Drum Modules & Brains',
                    'Electronic Drum Pads',
                    'Drum Triggers',
                    'Electronic Drum Amps',
                ],
                'Snare Drums' => [
                    'Wood Shell Snare Drums',
                    'Metal Shell Snare Drums',
                    'Piccolo Snare Drums',
                    'Marching Snare Drums',
                ],
                'Cymbals' => [
                    'Crash Cymbals',
                    'Ride Cymbals',
                    'Hi-Hat Cymbals',
                    'Splash Cymbals',
                    'China Cymbals',
                    'Effects & Stack Cymbals',
                    'Cymbal Packs',
                    'Gongs',
                ],
                'Drum Hardware' => [
                    'Bass Drum Pedals',
                    'Hi-Hat Stands',
                    'Snare Drum Stands',
                    'Cymbal Stands',
                    'Drum Thrones',
                    'Drum Racks',
                    'Multi Clamps & Mounts',
                ],
                'Hand Percussion' => [
                    'Cajons',
                    'Congas',
                    'Bongos',
                    'Djembes',
                    'Tambourines',
                    'Shakers & Blocks',
                    'Cowbells',
                    'Triangles & Chimes',
                    'Handpans & Tongue Drums',
                ],
                'Orchestral Percussion' => [
                    'Glockenspiels',
                    'Xylophones & Marimbas',
                    'Vibraphones',
                    'Timpani',
                    'Concert Bass Drums',
                    'Orchestral Cymbals',
                    'Tubular Bells',
                ],
                'Drum Consumables' => [
                    'Drum Heads',
                    'Drumsticks',
                    'Brushes, Rods & Mallets',
                    'Practice Pads',
                    'Drum Mutes & Dampening',
                    'Drum Keys & Tuning Tools',
                    'Drum Bags & Cases',
                    'Drum Lugs & Spares',
                ],
            ],

            'Keys & Synths' => [
                'Synthesisers' => [
                    'Analogue Synthesisers',
                    'Digital Synthesisers',
                    'Semi-Modular Synthesisers',
                    'Desktop & Rack Synths',
                    'Vintage Synthesisers',
                    'String Machines & Organs',
                ],
                'Modular & Eurorack' => [
                    'Eurorack Modules',
                    'Eurorack Cases & Power',
                    'Modular Sequencers',
                    'Patch Cables & Modular Accessories',
                ],
                'Digital Pianos' => [
                    'Home Digital Pianos',
                    'Stage Pianos',
                    'Portable Digital Pianos',
                    'Digital Piano Packages',
                ],
                'Acoustic Pianos' => [
                    'Upright Pianos',
                    'Grand Pianos',
                    'Player Pianos',
                ],
                'Keyboards' => [
                    'Arranger Keyboards',
                    'Workstation Keyboards',
                    'Beginner & Home Keyboards',
                    'Keytars',
                ],
                'Organs' => [
                    'Combo & Drawbar Organs',
                    'Home & Church Organs',
                    'Leslie & Rotary Speakers',
                    'Harmoniums & Pump Organs',
                ],
                'MIDI Controllers' => [
                    'MIDI Keyboard Controllers',
                    'Pad Controllers',
                    'DAW Control Surfaces',
                    'MIDI Wind & Guitar Controllers',
                    'MIDI Interfaces',
                ],
                'Grooveboxes & Samplers' => [
                    'Grooveboxes',
                    'Hardware Samplers',
                    'Drum Machines',
                    'Hardware Sequencers',
                    'Loop Stations',
                ],
                'Keyboard Accessories' => [
                    'Sustain & Expression Pedals',
                    'Keyboard Benches & Piano Stools',
                    'Keyboard Cases & Bags',
                    'Keyboard Dust Covers',
                ],
            ],

            'Studio & Recording' => [
                'Audio Interfaces' => [
                    'USB Audio Interfaces',
                    'Thunderbolt Audio Interfaces',
                    'Network & Dante Interfaces',
                    'Guitar & Mobile Interfaces',
                    'ADAT & Digital Expanders',
                ],
                'Microphones' => [
                    'Dynamic Microphones',
                    'Condenser Microphones',
                    'Ribbon Microphones',
                    'USB Microphones',
                    'Shotgun Microphones',
                    'Lavalier & Headset Microphones',
                    'Drum Microphone Kits',
                    'Measurement Microphones',
                    'Vintage & Valve Microphones',
                ],
                'Studio Monitors' => [
                    'Active Studio Monitors',
                    'Passive Studio Monitors',
                    'Studio Subwoofers',
                    'Monitor Controllers',
                    'Monitor Isolation Pads & Stands',
                ],
                'Studio Headphones' => [
                    'Closed-Back Headphones',
                    'Open-Back Headphones',
                    'In-Ear Monitors',
                    'Headphone Amplifiers',
                ],
                'Outboard & Processing' => [
                    'Microphone Preamps',
                    'Channel Strips',
                    'Compressors & Limiters',
                    'Outboard EQ',
                    'Hardware Reverb & Delay',
                    'Valve Processors',
                    'Patchbays',
                ],
                'Recorders' => [
                    'Multitrack Recorders',
                    'Portable Field Recorders',
                    'Handheld Recorders',
                    'Tape Machines & Reel-to-Reel',
                    'DAT & MiniDisc Recorders',
                ],
                'Mixing Desks' => [
                    'Analogue Mixing Desks',
                    'Digital Mixing Desks',
                    'Summing Mixers',
                    'Vintage Mixing Desks',
                ],
                'Music Software' => [
                    'DAW Software',
                    'Plugins & Virtual Instruments',
                    'Sample Libraries',
                    'Licences & Dongles',
                ],
                'Studio Accessories' => [
                    'Pop Filters',
                    'Shockmounts & Mic Clips',
                    'Reflection Filters',
                    'Acoustic Treatment',
                    'Studio Furniture & Desks',
                    'DI Boxes',
                    'Reamp Boxes',
                ],
            ],

            'Live Sound & PA' => [
                'PA Speakers' => [
                    'Active PA Speakers',
                    'Passive PA Speakers',
                    'Column PA Systems',
                    'PA Subwoofers',
                    'Portable & Battery PA',
                    'Install & Ceiling Speakers',
                ],
                'Stage Monitors',
                'Power Amplifiers',
                'Live Mixers' => [
                    'Analogue Live Mixers',
                    'Digital Live Mixers',
                    'Powered Mixers',
                    'Stage Boxes & Digital Snakes',
                ],
                'Wireless Systems' => [
                    'Wireless Microphone Systems',
                    'Wireless Instrument Systems',
                    'In-Ear Monitor Systems',
                    'Wireless Spares & Antennas',
                ],
                'Stage Lighting' => [
                    'LED Par Cans',
                    'Moving Head Lights',
                    'Lighting Controllers & Desks',
                    'Effect & Strobe Lighting',
                    'Smoke & Haze Machines',
                    'Lighting Stands & Trussing',
                ],
                'Live Sound Accessories' => [
                    'Speaker Stands & Poles',
                    'Analogue Snakes & Looms',
                    'Rigging & Clamps',
                    'Road & Flight Cases',
                    'Staging & Risers',
                    'Crowd Barriers & Cable Ramps',
                ],
            ],

            'DJ Equipment' => [
                'DJ Turntables' => [
                    'Direct Drive DJ Turntables',
                    'Belt Drive DJ Turntables',
                    'Portable & Scratch Turntables',
                ],
                'DJ Controllers',
                'DJ Mixers' => [
                    '2-Channel DJ Mixers',
                    '4-Channel DJ Mixers',
                    'Rotary Mixers',
                    'Battle Mixers',
                ],
                'Media Players & CDJs',
                'DJ Headphones',
                'Cartridges & Styli',
                'DJ Accessories' => [
                    'Slipmats',
                    'Record Bags & Cases',
                    'DJ Stands & Booths',
                    'DJ Lighting',
                    'Laptop Stands',
                ],
            ],

            'Hi-Fi & Home Audio' => [
                'Record Players & Turntables' => [
                    'Belt Drive Turntables',
                    'Direct Drive Turntables',
                    'All-in-One Record Players',
                    'Portable & Suitcase Record Players',
                    'Vintage Turntables',
                    'Turntable Plinths & Upgrades',
                ],
                'Tape & Cassette' => [
                    'Cassette Decks',
                    'Portable Cassette Players',
                    'Boomboxes & Radio Cassettes',
                    'Reel-to-Reel Machines',
                    'Tape Deck Spares & Belts',
                ],
                'CD & Digital Players' => [
                    'CD Players',
                    'MiniDisc Players',
                    'Network Streamers',
                    'DACs',
                ],
                'Hi-Fi Amplifiers' => [
                    'Integrated Amplifiers',
                    'Hi-Fi Power Amplifiers',
                    'Pre-Amplifiers',
                    'Valve Hi-Fi Amplifiers',
                    'AV Receivers',
                    'Vintage Hi-Fi Amplifiers',
                ],
                'Phono Stages',
                'Hi-Fi Speakers' => [
                    'Bookshelf Speakers',
                    'Floorstanding Speakers',
                    'Active Hi-Fi Speakers',
                    'Hi-Fi Subwoofers',
                    'Vintage Speakers',
                    'Speaker Stands & Spikes',
                ],
                'Hi-Fi Headphones',
                'Turntable Parts & Care' => [
                    'Turntable Cartridges',
                    'Replacement Styli',
                    'Headshells',
                    'Turntable Belts',
                    'Tonearms',
                    'Platters & Mats',
                    'Isolation Platforms & Feet',
                ],
            ],

            'Vinyl, Tapes & CDs' => [
                'Vinyl Records' => [
                    '12" Albums & LPs',
                    '12" Singles & Maxi Singles',
                    '7" Singles',
                    '10" Records',
                    'Picture Discs',
                    'Coloured Vinyl',
                    'Vinyl Box Sets',
                    '78 RPM & Shellac',
                    'Test Pressings & Acetates',
                    'Vinyl Job Lots & Collections',
                ],
                'Cassettes' => [
                    'Album Cassettes',
                    'Single Cassettes',
                    'Blank Cassettes',
                    'Cassette Box Sets',
                    'Demo & Promo Cassettes',
                ],
                'Compact Discs' => [
                    'CD Albums',
                    'CD Singles',
                    'CD Box Sets',
                    'Promo CDs',
                ],
                'Other Formats' => [
                    'Reel-to-Reel Tapes',
                    'MiniDiscs',
                    '8-Track Cartridges',
                    'DAT Tapes',
                ],
                'Record Care & Storage' => [
                    'Record Cleaning Machines',
                    'Record Cleaning Brushes & Fluid',
                    'Inner Sleeves',
                    'Outer Sleeves',
                    'Stylus Cleaners',
                    'Record Crates & Storage',
                    'Record Stands & Display',
                ],
            ],

            'Wind & Brass' => [
                'Saxophones' => [
                    'Alto Saxophones',
                    'Tenor Saxophones',
                    'Soprano Saxophones',
                    'Baritone Saxophones',
                ],
                'Trumpets & Cornets' => [
                    'Trumpets',
                    'Cornets',
                    'Flugelhorns',
                    'Pocket Trumpets',
                ],
                'Trombones' => [
                    'Tenor Trombones',
                    'Bass Trombones',
                    'Valve Trombones',
                ],
                'French Horns',
                'Tubas & Euphoniums' => [
                    'Tubas',
                    'Euphoniums',
                    'Baritone Horns',
                    'Tenor Horns',
                ],
                'Clarinets' => [
                    'Bb Clarinets',
                    'Bass Clarinets',
                    'Eb Clarinets',
                ],
                'Flutes' => [
                    'Concert Flutes',
                    'Piccolos',
                    'Alto & Bass Flutes',
                ],
                'Oboes & Bassoons' => [
                    'Oboes',
                    'Bassoons',
                    'Cor Anglais',
                ],
                'Harmonicas' => [
                    'Diatonic Harmonicas',
                    'Chromatic Harmonicas',
                    'Tremolo Harmonicas',
                ],
                'Recorders & Whistles' => [
                    'Recorders',
                    'Tin Whistles',
                    'Ocarinas',
                    'Melodicas',
                ],
                'Wind & Brass Accessories' => [
                    'Reeds',
                    'Mouthpieces',
                    'Ligatures & Caps',
                    'Valve & Slide Oils',
                    'Cleaning Swabs & Brushes',
                    'Wind Instrument Stands',
                ],
            ],

            'Orchestral Strings' => [
                'Violins' => [
                    'Full Size Violins',
                    'Fractional Violins',
                    'Electric Violins',
                ],
                'Violas',
                'Cellos' => [
                    'Acoustic Cellos',
                    'Electric Cellos',
                ],
                'Double Basses',
                'Harps' => [
                    'Concert Harps',
                    'Lever & Celtic Harps',
                ],
                'Bows' => [
                    'Violin Bows',
                    'Viola Bows',
                    'Cello Bows',
                    'Double Bass Bows',
                ],
                'Orchestral Accessories' => [
                    'Orchestral Strings',
                    'Rosin',
                    'Shoulder Rests',
                    'Chin Rests',
                    'Practice Mutes',
                    'String Instrument Cases',
                ],
            ],

            'Folk & Traditional' => [
                'Ukuleles' => [
                    'Soprano Ukuleles',
                    'Concert Ukuleles',
                    'Tenor Ukuleles',
                    'Baritone Ukuleles',
                    'Electro-Ukuleles',
                    'Bass Ukuleles',
                ],
                'Banjos' => [
                    '5-String Banjos',
                    '4-String Banjos',
                    'Banjo Ukuleles',
                    'Electric Banjos',
                ],
                'Mandolins' => [
                    'A-Style Mandolins',
                    'F-Style Mandolins',
                    'Electro-Mandolins',
                    'Mandolas & Octave Mandolins',
                ],
                'Lap Steel & Resophonic' => [
                    'Lap Steel Guitars',
                    'Pedal Steel Guitars',
                    'Dobros & Resonators',
                ],
                'Accordions & Concertinas' => [
                    'Piano Accordions',
                    'Button Accordions',
                    'Melodeons',
                    'Concertinas',
                ],
                'Bagpipes & Whistles' => [
                    'Highland Bagpipes',
                    'Practice Chanters',
                    'Uilleann Pipes',
                ],
                'World Instruments' => [
                    'Sitars & Tanpuras',
                    'Kalimbas & Thumb Pianos',
                    'Didgeridoos',
                    'Steel Pans',
                    'Hurdy Gurdies',
                    'Bouzoukis & Citterns',
                    'Dulcimers & Autoharps',
                ],
                'Folk Strings & Accessories' => [
                    'Ukulele Strings',
                    'Banjo Strings',
                    'Mandolin Strings',
                    'Folk Instrument Cases',
                ],
            ],

            'Cables, Power & Accessories' => [
                'Instrument & Speaker Cables' => [
                    'Jack to Jack Instrument Cables',
                    'Right-Angle Jack Cables',
                    'Coiled Instrument Cables',
                    'Patch Cables',
                    'Speaker Cables',
                    'Speakon Cables',
                ],
                'Microphone & Line Cables' => [
                    'XLR Cables',
                    'XLR to Jack Cables',
                    'Multicore Snakes & Looms',
                    'Insert & Y Cables',
                ],
                'Digital & Data Cables' => [
                    'MIDI Cables',
                    'USB Cables',
                    'Optical & S/PDIF Cables',
                    'AES/EBU Cables',
                    'Audio Network Cables',
                    'HDMI & Video Cables',
                ],
                'Hi-Fi & Home Cables' => [
                    'RCA Phono Cables',
                    'Speaker Wire',
                    'Banana Plug Cables',
                    'Turntable Ground Wires',
                    '3.5mm & Aux Cables',
                ],
                'Adapters & Connectors' => [
                    'Jack Adapters',
                    'XLR Adapters',
                    'Phono & RCA Adapters',
                    'Jack Plugs & Sockets',
                    'XLR Connectors',
                    'Speakon & Powercon Connectors',
                    'Cable Splitters & Isolators',
                    'Cable Testers',
                    'Solder, Heat Shrink & Tools',
                ],
                'Power' => [
                    'Pedal Power Supplies',
                    'Daisy Chain Cables',
                    'Single Pedal PSUs',
                    'Power Conditioners',
                    'IEC Mains Leads',
                    'Extension Blocks & Distro',
                    'Mains Adapters & PSUs',
                    'Batteries & Rechargeables',
                ],
                'Footswitches & Foot Pedals' => [
                    'Amp Footswitches',
                    'Expression Pedals',
                    'Volume Pedals',
                    'MIDI Foot Controllers',
                    'Page Turners & Utility Switches',
                ],
                'Stands & Mounts' => [
                    'Guitar Stands & Hangers',
                    'Keyboard Stands',
                    'Microphone Stands',
                    'Music Stands',
                    'Amp Stands & Tilters',
                    'Tablet & Phone Mounts',
                ],
                'Cases & Bags' => [
                    'Guitar Cases & Gig Bags',
                    'Bass Cases & Gig Bags',
                    'Pedalboard Cases',
                    '19" Rack Cases',
                    'Mixer & Controller Bags',
                    'Utility & Accessory Cases',
                ],
                'Tuners & Metronomes' => [
                    'Clip-On Tuners',
                    'Pedal Tuners',
                    'Rack & Desktop Tuners',
                    'Metronomes',
                    'Tuning Forks & Pitch Pipes',
                ],
                'Care & Cleaning' => [
                    'Instrument Polish & Cloths',
                    'Fretboard Conditioner',
                    'Contact Cleaner & Lubricant',
                    'Dust Covers',
                    'Humidifiers & Hygrometers',
                ],
                'Studio & Stage Consumables' => [
                    'Gaffer & Electrical Tape',
                    'Cable Ties & Velcro',
                    'Cable Labels & Markers',
                    'Blank Media',
                    'Spare Fuses & Valves',
                ],
            ],

            'Sheet Music & Tuition' => [
                'Sheet Music' => [
                    'Guitar Tab & Songbooks',
                    'Piano & Keyboard Sheet Music',
                    'Vocal Scores',
                    'Orchestral Parts & Scores',
                    'Drum Notation',
                ],
                'Tuition Books & Courses' => [
                    'Beginner Method Books',
                    'Grade & Exam Books',
                    'Theory & Harmony',
                    'Instructional DVDs & Courses',
                ],
                'Manuscript & Stationery' => [
                    'Manuscript Paper',
                    'Music Folders & Binders',
                    'Setlist & Cue Cards',
                ],
            ],
        ];
    }

    /**
     * Departments in the order they should be shown.
     *
     * Taken from the array's own key order rather than a second list, so
     * reordering the tree above reorders the site and there is no chance of
     * the two disagreeing.
     *
     * @return array<int, string>
     */
    public static function departments(): array
    {
        return array_keys(self::tree());
    }
}
