<?php

namespace App\Catalog;

/**
 * The brands a seller can pick from, grouped by the department they belong to.
 *
 * A closed list rather than a free text box, and that is a real trade-off
 * worth being honest about. Free text gives a seller every brand that has
 * ever existed and gives a buyer a filter listing "Fender", "fender",
 * "Fender USA" and "fener" as four different makes, which is not a filter at
 * all. The closed list keeps the facet usable, and OTHER catches the rest.
 *
 * Grouped by department so the picker can show a guitarist forty guitar
 * brands instead of four hundred of everything. The filter itself does not
 * use the grouping: it counts whatever is actually present in the results,
 * because a Yamaha appears in six departments and a buyer filtering drums
 * should see the drum ones.
 */
class Brands
{
    /** What a seller picks when their brand is not listed. */
    public const OTHER = 'Other';

    /** Unbranded, home built, or a brand that was never on the instrument. */
    public const UNBRANDED = 'Unbranded';

    /** @return array<string, array<int, string>> */
    public static function byDepartment(): array
    {
        return [
            'guitars' => [
                'Fender', 'Gibson', 'Epiphone', 'Squier', 'Ibanez', 'PRS', 'Gretsch',
                'Rickenbacker', 'Jackson', 'ESP', 'LTD', 'Schecter', 'Music Man', 'Sterling by Music Man',
                'Charvel', 'Danelectro', 'Duesenberg', 'Eastman', 'Reverend', 'Yamaha',
                'Cort', 'Harley Benton', 'Sire', 'Chapman', 'Vintage', 'Encore',
                'Martin', 'Taylor', 'Takamine', 'Seagull', 'Sigma', 'Tanglewood', 'Faith',
                'Guild', 'Washburn', 'Ovation', 'Lowden', 'Furch', 'Alvarez', 'Crafter',
                'Admira', 'Alhambra', 'La Patrie', 'Godin', 'Line 6', 'Strandberg',
                'Marshall', 'Orange', 'Vox', 'Blackstar', 'Laney', 'Fender Amps', 'Mesa/Boogie',
                'Hughes & Kettner', 'Peavey', 'Roland', 'Boss', 'Positive Grid', 'Victory',
                'Two Notes', 'Kemper', 'Neural DSP', 'Supro', 'Matchless', 'Friedman',
                'Electro-Harmonix', 'MXR', 'Dunlop', 'TC Electronic', 'Strymon', 'Walrus Audio',
                'JHS Pedals', 'EarthQuaker Devices', 'Wampler', 'Keeley', 'Digitech', 'Zoom',
                'Eventide', 'Chase Bliss', 'Source Audio', 'Empress Effects', 'Mooer', 'NUX',
                'Seymour Duncan', 'DiMarzio', 'Bare Knuckle', 'Fishman', 'LR Baggs', 'Graph Tech',
                'Hipshot', 'Gotoh', 'Grover', 'Schaller', 'Wilkinson', 'Floyd Rose', 'Switchcraft',
                "D'Addario", 'Ernie Ball', 'Elixir', 'Rotosound', 'DR Strings', 'GHS', 'Dean Markley',
                'Jim Dunlop', 'Shubb', 'Kyser', 'Levy\'s', 'Ortega',
            ],

            'bass-guitars' => [
                'Fender', 'Squier', 'Music Man', 'Sterling by Music Man', 'Ibanez', 'Yamaha',
                'Warwick', 'Spector', 'Sandberg', 'Sire', 'Lakland', 'Dingwall', 'Cort',
                'Rickenbacker', 'Gibson', 'Epiphone', 'Höfner', 'Harley Benton', 'Schecter',
                'ESP', 'LTD', 'Sadowsky', 'Fodera', 'Marcus Miller', 'Vintage', 'Aria',
                'Ampeg', 'Hartke', 'Markbass', 'Aguilar', 'Darkglass', 'Gallien-Krueger',
                'TC Electronic', 'Trace Elliot', 'Eden', 'Orange', 'Ashdown', 'Laney', 'Phil Jones',
                'EBS', 'Tech 21', 'Bergantino', 'Barefaced', 'Genzler',
                'Rotosound', "D'Addario", 'Ernie Ball', 'Elixir', 'La Bella', 'Thomastik-Infeld',
                'Bartolini', 'Nordstrand', 'Aguilar Pickups', 'Seymour Duncan',
            ],

            'drums-percussion' => [
                'Pearl', 'Tama', 'Ludwig', 'DW', 'PDP', 'Mapex', 'Sonor', 'Gretsch Drums',
                'Yamaha', 'Premier', 'Natal', 'British Drum Co', 'Canopus', 'C&C', 'Dixon',
                'Roland', 'Alesis', 'Yamaha DTX', '2Box', 'ATV', 'Millenium', 'Carlsbro',
                'Zildjian', 'Sabian', 'Paiste', 'Meinl', 'Istanbul Agop', 'Istanbul Mehmet',
                'Dream Cymbals', 'Turkish Cymbals', 'Bosphorus', 'Wuhan', 'Stagg', 'Anatolian',
                'Remo', 'Evans', 'Aquarian', 'Attack',
                'Vic Firth', 'Promark', 'Vater', 'Regal Tip', 'Ahead', 'Zildjian Sticks',
                'LP', 'Toca', 'Schlagwerk', 'Nino', 'Pearl Percussion', 'Gon Bops',
                'Adams', 'Bergerault', 'Musser', 'Gibraltar', 'Tama Hardware', 'Roc-N-Soc',
                'Protection Racket', 'Hardcase', 'Zarbo', 'Rockbag',
            ],

            'keys-synths' => [
                'Roland', 'Korg', 'Yamaha', 'Moog', 'Sequential', 'Dave Smith Instruments',
                'Nord', 'Clavia', 'Arturia', 'Behringer', 'Novation', 'Elektron',
                'Teenage Engineering', 'Modal Electronics', 'ASM', 'Waldorf', 'Access',
                'Studiologic', 'Casio', 'Kawai', 'Kurzweil', 'Alesis', 'Akai Professional',
                'Native Instruments', 'M-Audio', 'Nektar', 'Arturia KeyLab', 'Doepfer',
                'Make Noise', 'Mutable Instruments', 'Intellijel', 'Erica Synths', 'ALM Busy Circuits',
                'Expert Sleepers', '4ms', 'Tiptop Audio', 'Befaco', 'Pittsburgh Modular',
                'Oberheim', 'Ensoniq', 'E-MU', 'Hammond', 'Vox Continental', 'Farfisa',
                'Crumar', 'Viscount', 'Steinway & Sons', 'Bösendorfer', 'Bechstein',
                'Schimmel', 'Broadwood', 'Challen', 'Danemann', 'Chappell',
            ],

            'studio-recording' => [
                'Focusrite', 'Universal Audio', 'PreSonus', 'MOTU', 'RME', 'Audient',
                'SSL', 'Steinberg', 'Antelope Audio', 'Apogee', 'Arturia', 'Behringer',
                'Zoom', 'Tascam', 'Native Instruments', 'IK Multimedia', 'ESI',
                'Shure', 'Sennheiser', 'AKG', 'Neumann', 'Rode', 'Audio-Technica',
                'Beyerdynamic', 'Aston Microphones', 'Warm Audio', 'sE Electronics', 'Lewitt',
                'Electro-Voice', 'Telefunken', 'Coles', 'Royer', 'Blue', 'Samson', 'Sontronics',
                'Genelec', 'KRK', 'Adam Audio', 'Yamaha', 'Mackie', 'JBL', 'Dynaudio',
                'Focal', 'Neumann Monitors', 'Eve Audio', 'IK Multimedia iLoud', 'PMC', 'Kali Audio',
                'API', 'Neve', 'Chandler Limited', 'Empirical Labs', 'Rupert Neve Designs',
                'Golden Age Project', 'Klark Teknik', 'dbx', 'Drawmer', 'TL Audio', 'ART',
                'Radial', 'Palmer', 'Orchid Electronics', 'Auralex', 'GIK Acoustics',
                'Avid', 'Ableton', 'Image-Line', 'PropellerHead', 'Waves', 'Slate Digital',
                'Output', 'Spitfire Audio', 'Toontrack', 'XLN Audio', 'Arturia Software',
            ],

            'live-sound-pa' => [
                'QSC', 'RCF', 'JBL', 'Electro-Voice', 'Yamaha', 'Mackie', 'Bose',
                'HK Audio', 'dB Technologies', 'LD Systems', 'Turbosound', 'Alto Professional',
                'FBT', 'Nexo', 'd&b audiotechnik', 'Martin Audio', 'Void Acoustics', 'Peavey',
                'Allen & Heath', 'Midas', 'Soundcraft', 'Behringer', 'PreSonus', 'DiGiCo',
                'Crown', 'Lab.gruppen', 'Powersoft', 't.amp', 'Crest Audio',
                'Shure', 'Sennheiser', 'Audio-Technica', 'Line 6 Relay', 'Trantec', 'Xvive',
                'Chauvet', 'ADJ', 'Martin Professional', 'Robe', 'Eurolite', 'Showtec',
                'Equinox', 'Antari', 'Le Maitre', 'Zero 88', 'Avolites',
                'K&M', 'Gravity', 'Ultimate Support', 'Doughty', 'Global Truss', 'Thon',
            ],

            'dj-equipment' => [
                'Pioneer DJ', 'Technics', 'Denon DJ', 'Numark', 'Rane', 'Reloop', 'Native Instruments',
                'Allen & Heath Xone', 'Serato', 'Roland DJ', 'Hercules', 'Stanton', 'Gemini',
                'Audio-Technica', 'Ortofon', 'Shure', 'Sennheiser', 'AIAIAI', 'V-MODA',
                'Vestax', 'Ecler', 'Omnitronic', 'Zomo', 'Magma', 'UDG', 'Decksaver',
            ],

            'hi-fi-home-audio' => [
                // Turntables and record players, which is where a used market
                // has the longest tail: half of these have not made a deck in
                // forty years and all of them still turn up in lofts.
                'Technics', 'Rega', 'Pro-Ject', 'Audio-Technica', 'Thorens', 'Linn',
                'Systemdek', 'Michell Engineering', 'SME', 'Clearaudio', 'VPI', 'Music Hall',
                'Dual', 'Garrard', 'Lenco', 'Sansui', 'Sony', 'Denon', 'Pioneer', 'JVC',
                'Kenwood', 'Akai', 'Marantz', 'Philips', 'Sherwood', 'Trio', 'Aiwa',
                'Goldring', 'Ariston', 'NAD', 'Fluance', 'U-Turn Audio', 'Crosley',
                'Victrola', 'ION Audio', 'Lauren', 'Steepletone', 'Roberts', 'Ruark',
                'Bang & Olufsen', 'Braun', 'Revox', 'Nakamichi', 'TEAC', 'Luxman',
                'Ortofon', 'Shure', 'Nagaoka', 'Sumiko', 'Grado', 'Denon Cartridges',
                'Cambridge Audio', 'Arcam', 'Naim', 'Quad', 'Musical Fidelity', 'Rotel',
                'Cyrus', 'Creek', 'Exposure', 'Audiolab', 'Leak', 'Sugden', 'Roksan',
                'Onkyo', 'Yamaha', 'Harman Kardon', 'Rega Amps', 'McIntosh',
                'KEF', 'Bowers & Wilkins', 'Mission', 'Wharfedale', 'Monitor Audio',
                'Tannoy', 'Celestion', 'Castle', 'Q Acoustics', 'Dali', 'Elac', 'Spendor',
                'Harbeth', 'ProAc', 'Epos', 'Acoustic Energy', 'JPW', 'Goodmans',
                'Sennheiser', 'Beyerdynamic', 'Grado Headphones', 'Focal', 'Sonos',
            ],

            'vinyl-tapes-cds' => [
                // Records are filed by label rather than by brand. These are
                // the labels a UK secondhand crate most often turns up.
                'Blue Note', 'Verve', 'Impulse!', 'Motown', 'Stax', 'Atlantic', 'Chess',
                'Columbia', 'CBS', 'RCA Victor', 'Decca', 'Deutsche Grammophon', 'EMI',
                'Parlophone', 'Harvest', 'Island', 'Chrysalis', 'Virgin', 'Charisma',
                'Vertigo', 'Warner Bros', 'Elektra', 'Asylum', 'Reprise', 'A&M',
                'Polydor', 'Philips Records', 'Mercury', 'Capitol', 'Apple Records',
                'Rough Trade', 'Factory', '4AD', 'Mute', 'Creation', 'Domino', 'Beggars Banquet',
                'Stiff', 'Two-Tone', 'Trojan', 'Studio One', 'Greensleeves',
                'Warp', 'Ninja Tune', 'XL Recordings', 'Hyperdub', 'R&S', 'Defected',
                'Sub Pop', 'Matador', 'Merge', 'Dischord', 'SST', 'Epitaph', 'Roadrunner',
                'Music On Vinyl', 'Rhino', 'Sundazed', 'Analogue Productions', 'Mobile Fidelity',
                'Demon Records', 'Third Man Records', 'Sacred Bones',
            ],

            'wind-brass' => [
                'Yamaha', 'Selmer', 'Yanagisawa', 'Jupiter', 'Trevor James', 'Buffet Crampon',
                'Bach', 'Conn', 'King', 'Besson', 'Courtois', 'Getzen', 'Schilke', 'Stomvi',
                'Miraphone', 'Wessex', 'John Packer', 'Odyssey', 'Stagg', 'Elkhart',
                'Vandoren', 'Rico', "D'Addario Woodwinds", 'Legere', 'Otto Link', 'Meyer',
                'Denis Wick', 'Vincent Bach Mouthpieces', 'BG', 'Rovner',
                'Hohner', 'Lee Oskar', 'Suzuki', 'Seydel', 'Tombo',
                'Aulos', 'Mollenhauer', 'Moeck', 'Clarke', 'Generation', 'Shaw',
            ],

            'orchestral-strings' => [
                'Stentor', 'Hidersine', 'Primavera', 'Yamaha', 'Eastman', 'Gewa', 'Gliga',
                'Scott Cao', 'Andreas Zeller', 'Antoni', 'Forenza', 'Archer', 'NS Design',
                'Yamaha Silent', 'Wood Violins', 'Realist',
                'Thomastik-Infeld', 'Pirastro', "D'Addario Orchestral", 'Larsen', 'Jargar',
                'Prim', 'Corelli', 'Warchal',
                'Wolf', 'Kun', 'Everest', 'Bonmusica', 'Hidersine Rosin', 'Pirastro Rosin',
                'Salvi', 'Lyon & Healy', 'Camac', 'Aoyama',
            ],

            'folk-traditional' => [
                'Kala', 'Makala', 'Lanikai', 'Ortega', 'Cordoba', 'Kanile\'a', 'KoAloha',
                'Martin', 'Fender', 'Luna', 'Mahalo', 'Octopus', 'Tanglewood', 'Snail',
                'Deering', 'Gold Tone', 'Recording King', 'Ozark', 'Barnes & Mullins',
                'Ashbury', 'Countryman', 'Vangoa',
                'Eastman', 'The Loar', 'Kentucky', 'Ibanez', 'Fylde', 'Ashbury Mandolins',
                'Hohner', 'Paolo Soprani', 'Weltmeister', 'Roland FR', 'Saltarelle',
                'Castagnari', 'Wheatstone', 'Lachenal',
                'R.G. Hardie', 'McCallum', 'Wallace Bagpipes', 'Gibson Bagpipes',
                'Meinl Sonic Energy', 'Hokema', 'Hluru', 'Paiste Sound Creation',
            ],

            'cables-power-accessories' => [
                'Neutrik', 'Switchcraft', 'Amphenol', 'Van Damme', 'Mogami', 'Canare',
                'Klotz', 'Sommer Cable', 'Cordial', 'Planet Waves', "D'Addario",
                'Ernie Ball', 'Fender Cables', 'Orange Cables', 'Lava Cable', 'Evidence Audio',
                'George L\'s', 'Rockboard', 'Pedaltrain', 'Temple Audio', 'Schmidt Array',
                'Voodoo Lab', 'Strymon', 'Truetone', 'MXR', 'Cioks', 'Harley Benton Power',
                'Behringer', 'Furman', 'Samson Power', 'Rolls', 'ART',
                'K&M', 'Hercules Stands', 'Gravity', 'Ultimate Support', 'On-Stage',
                'Manhasset', 'RATstands', 'Quik Lok', 'Stagg Stands',
                'Gator', 'SKB', 'Thon', 'Rockcase', 'Mono', 'Fusion Bags', 'Kinsman',
                'Hiscox', 'Calton', 'Protection Racket', 'Rockbag', 'Ritter',
                'Boss Tuners', 'TC Electronic Polytune', 'Snark', 'Peterson', 'Korg Tuners',
                'Wittner', 'Seiko', 'Dunlop Care', 'MusicNomad', 'Planet Waves Care',
                'Chord', 'QED', 'Audioquest', 'Atlas Cables', 'Le Mark', 'Rocket Tape',
            ],

            'sheet-music-tuition' => [
                'Hal Leonard', 'Wise Publications', 'Music Sales', 'Faber Music',
                'Alfred Music', 'Boosey & Hawkes', 'Schott', 'Bärenreiter', 'Henle',
                'ABRSM', 'Trinity College London', 'Rockschool', 'RSL Awards',
                'Mel Bay', 'Berklee Press', 'Hamilton', 'Chester Music', 'Peters Edition',
            ],
        ];
    }

    /**
     * Every brand, once, alphabetically.
     *
     * Used for validation rather than display, since nobody wants to scroll
     * eight hundred names to find Fender.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $brands = array_merge(...array_values(self::byDepartment()));
        $brands[] = self::OTHER;
        $brands[] = self::UNBRANDED;

        $unique = array_values(array_unique($brands));

        // Case insensitive, so "de Blasio" style lowercase prefixes do not
        // sort into their own block away from everything else.
        usort($unique, static fn (string $a, string $b) => strcasecmp($a, $b));

        return $unique;
    }

    /**
     * The brands offered for one department, plus the two catch-alls.
     *
     * @return array<int, string>
     */
    public static function forDepartment(string $departmentSlug): array
    {
        $brands = self::byDepartment()[$departmentSlug] ?? [];

        $brands = array_values(array_unique($brands));
        usort($brands, static fn (string $a, string $b) => strcasecmp($a, $b));

        return [...$brands, self::UNBRANDED, self::OTHER];
    }
}
