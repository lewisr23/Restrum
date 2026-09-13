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
     * The ids of every listing matching a text query, best match first.
     *
     * Every match, not a page of them, and that is the change this method
     * represents. Filtering and counting now happen in MySQL, and a facet
     * count has to be computed over the whole result set: counting the brands
     * on page one would tell a buyer there are three Fenders when there are
     * ninety. So the cluster's job narrowed to the one thing it is better at
     * than SQL, which is deciding what the words mean.
     *
     * $limit caps how far that goes. Past it, the counts describe the most
     * relevant slice rather than everything, which is a real limitation and a
     * far smaller one than keeping two filter implementations in step.
     *
     * @return int[]
     */
    public function matchingIds(string $term, int $limit = 1000): array
    {
        $body = [
            'query' => $this->textQuery($term),
            'size' => $limit,
            // Only the ids are used: they go straight into a MySQL query.
            '_source' => false,
            'sort' => ['_score', ['created_at' => 'desc']],
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
                            // merely filed under synths. Brand is weighted
                            // near the title, because "Technics" typed into a
                            // search box is almost always the make and almost
                            // never a word from a description.
                            'fields' => [
                                'title^3', 'brand_text^2', 'description',
                                'attributes_text', 'location', 'category_text^0.5',
                            ],
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
    public function similarTo(int $listingId, string $categoryPath, int $limit = 6): array
    {
        $body = [
            'query' => [
                'bool' => [
                    'must' => [[
                        'more_like_this' => [
                            'fields' => ['title', 'description', 'brand_text', 'attributes_text', 'category_text'],
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
                        // Same branch of the tree: a delay pedal is not a
                        // useful suggestion under a drum kit however many
                        // words the two descriptions happen to share. The
                        // caller passes the PARENT path rather than the leaf,
                        // because a buyer looking at one pressing of a record
                        // wants the others, and those are often filed a rung
                        // away. Matching on category_ancestors is what makes
                        // "anywhere under this branch" a single term query.
                        ['term' => ['category_ancestors' => $categoryPath]],
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

    // Price filtering and the sold-last sort used to live here, as an
    // Elasticsearch range filter and a sort script. Both moved to SQL along
    // with the rest of the filtering, so that what a buyer sees and what the
    // filter counts claim can never come from two different engines with two
    // different ideas of the current price.
}
