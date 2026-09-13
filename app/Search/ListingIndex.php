<?php

namespace App\Search;

use App\Models\Category;
use App\Models\Listing;
use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Log;

/**
 * Owns the shape of the listings index and everything that writes to it.
 *
 * Searching lives in ListingSearch. The split matters because writes and
 * reads fail differently: a failed write should be logged and swallowed so
 * a seller can still save their listing, while a failed read falls back to
 * SQL so browsing still works.
 */
class ListingIndex
{
    public function __construct(
        private readonly Client $client,
        private readonly string $index,
    ) {}

    public function name(): string
    {
        return $this->index;
    }

    /**
     * Settings and mappings for the index.
     *
     * The analyzers are where most of the value is. Musicians do not type
     * catalogue names: they type "strat", "tele", "sm58", and they misspell
     * things. Three analyzers cover that between them.
     */
    public function definition(): array
    {
        return [
            'settings' => [
                // One shard: the whole corpus is a few thousand listings, and
                // a single shard means term frequencies are exact rather than
                // approximated per shard, so relevance ordering is stable.
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
                'analysis' => [
                    'filter' => [
                        // "strat" matches "Stratocaster" by indexing prefixes
                        // of each term. Applied at index time only, never at
                        // search time, or a search for "strat" would also
                        // generate "s" and "st" and match almost everything.
                        'gear_edge_ngram' => [
                            'type' => 'edge_ngram',
                            'min_gram' => 3,
                            'max_gram' => 12,
                        ],
                        'english_stemmer' => [
                            'type' => 'stemmer',
                            'language' => 'english',
                        ],
                        // Splits "SM58" into "sm" and "58" while keeping the
                        // original and a joined form, so all three spellings
                        // a person might type reach the same document.
                        'gear_word_delimiter' => [
                            'type' => 'word_delimiter_graph',
                            'preserve_original' => true,
                            'catenate_all' => true,
                        ],
                        // Instrument slang, mapped onto what sellers actually
                        // write in a title.
                        'gear_synonyms' => [
                            'type' => 'synonym',
                            'synonyms' => [
                                'strat, stratocaster',
                                'tele, telecaster',
                                'les paul, lp',
                                'bass guitar, bass',
                                'amp, amplifier, combo',
                                'mic, microphone',
                                'keys, keyboard, synthesiser, synthesizer, synth',
                                'pedal, stompbox, fx',
                                'interface, audio interface, soundcard',
                                'acoustic, acoustic guitar',
                            ],
                        ],
                    ],
                    'analyzer' => [
                        // The main one: English stemming plus the synonyms, so
                        // "amplifiers" and "combo" both reach the same term.
                        'gear_text' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'gear_synonyms', 'english_stemmer'],
                        ],
                        // Index time only, see the filter comment above.
                        'gear_prefix' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'gear_edge_ngram'],
                        ],
                        // Model numbers: "SM58", "DD-7", "2i2". Splitting on
                        // the letter to digit boundary means "sm 58" finds
                        // "SM58" and the other way round.
                        'gear_model' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'gear_word_delimiter'],
                        ],
                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => [
                        'type' => 'text',
                        'analyzer' => 'gear_text',
                        'fields' => [
                            // Searched with the standard analyzer, so a query
                            // for "strat" hits the prefixes indexed here.
                            'prefix' => [
                                'type' => 'text',
                                'analyzer' => 'gear_prefix',
                                'search_analyzer' => 'standard',
                            ],
                            'model' => [
                                'type' => 'text',
                                'analyzer' => 'gear_model',
                            ],
                        ],
                    ],
                    'description' => [
                        'type' => 'text',
                        'analyzer' => 'gear_text',
                    ],
                    'location' => [
                        'type' => 'text',
                        'fields' => ['keyword' => ['type' => 'keyword']],
                    ],
                    'seller_username' => ['type' => 'keyword'],

                    // The leaf this listing is filed in.
                    'category_path' => ['type' => 'keyword'],

                    // Every ancestor path as well, so filtering a whole
                    // department is still a single term query. The
                    // alternative is a prefix query on category_path, which
                    // cannot use the same index structure and gets slower as
                    // the tree grows.
                    'category_ancestors' => ['type' => 'keyword'],

                    // The category's names, analysed rather than exact.
                    // category_ancestors is the filter; this is so that
                    // someone typing "synth" finds the synths, which is an
                    // obvious expectation a keyword field cannot meet.
                    'category_text' => ['type' => 'text', 'analyzer' => 'gear_text'],

                    'brand' => ['type' => 'keyword'],
                    'brand_text' => ['type' => 'text', 'analyzer' => 'gear_text'],

                    // The filter answers as free text, so a search for
                    // "picture disc" or "left handed" finds listings whose
                    // title never says so but whose attributes do.
                    'attributes_text' => ['type' => 'text', 'analyzer' => 'gear_text'],

                    'condition' => ['type' => 'keyword'],
                    'status' => ['type' => 'keyword'],
                    'price' => ['type' => 'scaled_float', 'scaling_factor' => 100],
                    'created_at' => ['type' => 'date'],
                ],
            ],
        ];
    }

    /**
     * Every name on the way down to this category, as one string.
     *
     * "Guitars Electric Guitars Solid Body Electric Guitars" rather than just
     * the leaf, so a search for "guitar" reaches a listing filed under a leaf
     * whose own name happens not to contain the word, which is most of them
     * once a tree is this deep.
     */
    public static function categoryLabel(?Category $category): ?string
    {
        if ($category === null) {
            return null;
        }

        return Category::whereIn('path', $category->pathSegments())
            ->orderBy('depth')
            ->pluck('name')
            ->implode(' ');
    }

    public function exists(): bool
    {
        return $this->client->indices()->exists(['index' => $this->index])->asBool();
    }

    /**
     * Creates the index. Dropping first when asked is how a mapping change
     * gets applied, since mappings are immutable once a field is indexed.
     */
    public function create(bool $dropExisting = false): void
    {
        if ($this->exists()) {
            if (! $dropExisting) {
                return;
            }
            $this->client->indices()->delete(['index' => $this->index]);
        }

        $this->client->indices()->create([
            'index' => $this->index,
            'body' => $this->definition(),
        ]);
    }

    public function delete(): void
    {
        if ($this->exists()) {
            $this->client->indices()->delete(['index' => $this->index]);
        }
    }

    /**
     * The document shape. Deliberately flat and self contained: a search hit
     * carries everything needed to rank it, and nothing that would go stale
     * in a way nobody notices (media, saved state, price insight all come
     * from MySQL when the results are hydrated).
     */
    public function document(Listing $listing): array
    {
        // loadMissing rather than load: the reindex command eager loads these
        // for the whole chunk, and re-fetching per document would turn one
        // bulk index into three queries per listing.
        $listing->loadMissing('seller', 'category', 'attributeValues');

        return [
            'id' => $listing->id,
            'title' => $listing->title,
            'description' => $listing->description,
            'location' => $listing->location,
            'seller_username' => $listing->seller?->username,
            'category_path' => $listing->category?->path,
            'category_ancestors' => $listing->category?->pathSegments() ?? [],
            'category_text' => self::categoryLabel($listing->category),
            'brand' => $listing->brand,
            'brand_text' => $listing->brand,
            'attributes_text' => $listing->attributeValues->pluck('value')->implode(' '),
            'condition' => $listing->condition,
            'status' => $listing->status,
            'price' => (float) $listing->price,
            'created_at' => $listing->created_at?->toIso8601String(),
        ];
    }

    /**
     * Indexes one listing. Never throws: a seller saving a listing should not
     * see a 500 because the search cluster is down, and the row is still in
     * MySQL for the next reindex to pick up.
     */
    public function index(Listing $listing): void
    {
        try {
            $this->client->index([
                'index' => $this->index,
                'id' => (string) $listing->id,
                'body' => $this->document($listing),
                // Visible to the next search rather than up to a second
                // later. The volume here is nowhere near high enough for the
                // usual argument against this to apply, and a seller not
                // finding their own listing straight after posting it reads
                // as the feature being broken.
                'refresh' => 'wait_for',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not index listing', ['id' => $listing->id, 'error' => $e->getMessage()]);
        }
    }

    public function remove(int $listingId): void
    {
        try {
            $this->client->delete([
                'index' => $this->index,
                'id' => (string) $listingId,
                'refresh' => 'wait_for',
            ]);
        } catch (\Throwable $e) {
            // A 404 here is the desired end state anyway.
            Log::debug('Could not remove listing from index', ['id' => $listingId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Bulk indexes a chunk of listings and returns how many were written.
     * Unlike index() this does throw, because the only caller is the reindex
     * command, where silence would mean reporting success over a broken run.
     */
    public function bulkIndex(iterable $listings): int
    {
        $body = [];
        $count = 0;

        foreach ($listings as $listing) {
            $body[] = ['index' => ['_index' => $this->index, '_id' => (string) $listing->id]];
            $body[] = $this->document($listing);
            $count++;
        }

        if ($count === 0) {
            return 0;
        }

        $response = $this->client->bulk(['body' => $body]);

        if ($response['errors'] ?? false) {
            $firstError = collect($response['items'] ?? [])
                ->pluck('index.error.reason')
                ->filter()
                ->first();

            throw new \RuntimeException('Bulk indexing reported errors: '.($firstError ?? 'unknown'));
        }

        return $count;
    }

    public function refresh(): void
    {
        $this->client->indices()->refresh(['index' => $this->index]);
    }
}
