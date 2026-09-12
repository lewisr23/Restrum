<?php

namespace App\Search;

use Elastic\Elasticsearch\Client;

/**
 * Builds and runs the listing search query.
 *
 * Returns ids and a total rather than models. The documents hold only what
 * is needed to rank a result; everything the API actually serialises (media,
 * seller, saved state, price insight) comes from MySQL, so there is one
 * source of truth for what a listing currently is and the index can never
 * serve a stale price.
 */
class ListingSearch
{
    public function __construct(
        private readonly Client $client,
        private readonly string $index,
    ) {}

    /**
     * @param  array{search?: ?string, category?: ?string, min_price?: ?float, max_price?: ?float}  $filters
     * @return array{ids: int[], total: int}
     */
    public function search(array $filters, int $page = 1, int $perPage = 20): array
    {
        $term = trim((string) ($filters['search'] ?? ''));

        $query = [
            // Filters do not affect the score and are cacheable, so category
            // and price go here rather than in must.
            'filter' => array_values(array_filter([
                isset($filters['category']) && $filters['category'] !== null
                    ? ['term' => ['category' => $filters['category']]]
                    : null,
                $this->priceRange($filters),
            ])),
        ];

        if ($term !== '') {
            $query['must'] = [$this->textQuery($term)];
        } else {
            $query['must'] = [['match_all' => new \stdClass]];
        }

        $body = [
            'query' => ['bool' => $query],
            'from' => ($page - 1) * $perPage,
            'size' => $perPage,
            // Only fetch what is used: the ids drive a single MySQL query.
            '_source' => false,
            'track_total_hits' => true,
            'sort' => $this->sort($term !== ''),
        ];

        $response = $this->client->search([
            'index' => $this->index,
            'body' => $body,
        ]);

        $hits = $response['hits'] ?? [];

        return [
            'ids' => array_map(static fn ($hit) => (int) $hit['_id'], $hits['hits'] ?? []),
            'total' => (int) ($hits['total']['value'] ?? 0),
        ];
    }

    /**
     * Three ways of matching the same words, combined so that whichever one
     * fires contributes to the score.
     */
    private function textQuery(string $term): array
    {
        return [
            'bool' => [
                'should' => [
                    // Whole words, stemmed, synonyms applied. The main path,
                    // and title counts for three times a description hit.
                    [
                        'multi_match' => [
                            'query' => $term,
                            // Category sits below description: a listing
                            // whose text is about a synth beats one that is
                            // merely filed under synths.
                            'fields' => ['title^3', 'description', 'location', 'category_text^0.5'],
                            'type' => 'best_fields',
                            // One edit for short words, two for longer ones,
                            // which covers "telecastor" without letting
                            // three letter queries match everything.
                            'fuzziness' => 'AUTO',
                            'prefix_length' => 1,
                        ],
                    ],
                    // Prefixes: "strat" reaching "Stratocaster". Scored below
                    // a real word match so exact titles still win.
                    [
                        'match' => [
                            'title.prefix' => [
                                'query' => $term,
                                'boost' => 2,
                            ],
                        ],
                    ],
                    // Model numbers, where "sm 58" and "SM58" are the same
                    // thing to a human and two tokenisations to a machine.
                    [
                        'match' => [
                            'title.model' => [
                                'query' => $term,
                                'boost' => 2,
                            ],
                        ],
                    ],
                    // An exact phrase in the title is the strongest signal
                    // there is, so it gets the largest boost.
                    [
                        'match_phrase' => [
                            'title' => [
                                'query' => $term,
                                'boost' => 4,
                            ],
                        ],
                    ],
                ],
                'minimum_should_match' => 1,
            ],
        ];
    }

    /**
     * Listings similar to the one being viewed.
     *
     * more_like_this asks Elasticsearch which documents share the terms that
     * make this one distinctive, judged by how rare those terms are across
     * the whole index. So "Fender Stratocaster Sunburst" is pulled towards
     * other Strats rather than towards everything with "Fender" in it, and
     * none of that has to be hand tuned.
     *
     * @return int[]
     */
    public function similarTo(int $listingId, string $category, int $limit = 6): array
    {
        $body = [
            'query' => [
                'bool' => [
                    'must' => [[
                        'more_like_this' => [
                            'fields' => ['title', 'description', 'category_text'],
                            'like' => [[
                                '_index' => $this->index,
                                '_id' => (string) $listingId,
                            ]],
                            // A term has to appear in the source document at
                            // least once and in at least two documents
                            // overall. The defaults are 2 and 5, which on an
                            // index this size discard almost everything.
                            'min_term_freq' => 1,
                            'min_doc_freq' => 2,
                            // Enough terms to describe the item, few enough
                            // that the query stays cheap.
                            'max_query_terms' => 20,
                        ],
                    ]],
                    'filter' => [
                        // Same category: a delay pedal is not a useful
                        // suggestion under a drum kit however many words the
                        // two descriptions happen to share.
                        ['term' => ['category' => $category]],
                        // Nobody wants to be recommended something they
                        // cannot buy.
                        ['term' => ['status' => 'ACTIVE']],
                    ],
                    'must_not' => [
                        ['ids' => ['values' => [(string) $listingId]]],
                    ],
                ],
            ],
            'size' => $limit,
            '_source' => false,
        ];

        $response = $this->client->search([
            'index' => $this->index,
            'body' => $body,
        ]);

        return array_map(
            static fn ($hit) => (int) $hit['_id'],
            $response['hits']['hits'] ?? [],
        );
    }

    private function priceRange(array $filters): ?array
    {
        $range = array_filter([
            'gte' => $filters['min_price'] ?? null,
            'lte' => $filters['max_price'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        return $range === [] ? null : ['range' => ['price' => $range]];
    }

    /**
     * Sold listings sink to the bottom either way, matching how the SQL path
     * behaves. Above that line, a search sorts by relevance and an unfiltered
     * browse sorts by newest, because "most relevant" is meaningless when
     * every document matched equally.
     */
    private function sort(bool $hasSearchTerm): array
    {
        $sold = [
            '_script' => [
                'type' => 'number',
                'script' => "doc['status'].value == 'SOLD' ? 1 : 0",
                'order' => 'asc',
            ],
        ];

        return $hasSearchTerm
            ? [$sold, '_score', ['created_at' => 'desc']]
            : [$sold, ['created_at' => 'desc']];
    }
}
