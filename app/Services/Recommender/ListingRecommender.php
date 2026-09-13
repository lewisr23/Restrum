<?php

namespace App\Services\Recommender;

use Anthropic\Client;
use Anthropic\Messages\ToolUseBlock;
use App\Catalog\ListingFilter;
use App\Models\Category;
use App\Models\Listing;
use Illuminate\Support\Facades\Log;

/**
 * The gear adviser: a conversation with Claude that can search this
 * marketplace and point at real listings.
 *
 * The design decision worth explaining is that Claude does not get a dump of
 * the catalog. It gets two tools and has to go and look, which matters for
 * three reasons. A dump would be thousands of listings in every request, paid
 * for on every turn. It would go stale between the request and the answer.
 * And a model reasoning over a list it was handed will cheerfully recommend
 * the closest thing in that list, where one that has to search comes back
 * empty and says so.
 *
 * The search tool is the same ListingFilter the browse page uses. That is
 * deliberate: the adviser cannot find anything a buyer could not have found
 * themselves with the filters, and it cannot see sold listings or anyone's
 * private data, because the filter has no concept of them.
 */
class ListingRecommender
{
    /** How many listings one search may return. Enough to choose from. */
    private const SEARCH_LIMIT = 8;

    /** Cap on what a single answer can cite, so the widget stays readable. */
    private const MAX_CITED = 4;

    public function __construct(private readonly ?Client $client) {}

    public function isConfigured(): bool
    {
        return $this->client !== null;
    }

    /**
     * Answer one question, given the conversation so far.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{reply: string, listing_ids: array<int, int>}
     */
    public function reply(array $history): array
    {
        if ($this->client === null) {
            throw new RecommenderUnavailable('The gear adviser is not configured.');
        }

        $messages = array_map(
            static fn (array $turn) => ['role' => $turn['role'], 'content' => $turn['content']],
            $history,
        );

        // Every listing the model has been shown across this answer. The
        // citations it writes are checked against this, so it cannot invent
        // an id or quote one from a previous question that has since sold.
        $seen = [];
        $rounds = (int) config('services.anthropic.max_tool_rounds');

        $response = $this->send($messages);

        for ($round = 0; $round < $rounds; $round++) {
            if ($response->stopReason !== 'tool_use') {
                break;
            }

            $results = [];

            foreach ($response->content as $block) {
                if (! $block instanceof ToolUseBlock) {
                    continue;
                }

                [$output, $ids] = $this->runTool($block->name, $block->input);
                $seen = [...$seen, ...$ids];

                $results[] = [
                    'type' => 'tool_result',
                    'toolUseID' => $block->id,
                    'content' => $output,
                ];
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $results];

            $response = $this->send($messages);
        }

        $text = $this->textOf($response);

        return [
            'reply' => $this->stripCitations($text),
            'listing_ids' => $this->citedIds($text, array_unique($seen)),
        ];
    }

    private function send(array $messages)
    {
        return $this->client->messages->create(
            model: (string) config('services.anthropic.model'),
            maxTokens: 2000,
            system: [[
                'type' => 'text',
                'text' => $this->systemPrompt(),
                // The prompt and the tool list are identical on every turn of
                // every conversation, so they are the one thing here worth
                // caching. Volatile content (the question) comes after it.
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            // Low effort on purpose. This is "find me a cheap starter bass",
            // not a research problem, and the difference in answer quality
            // does not justify the difference in what a public widget costs
            // to run.
            outputConfig: ['effort' => 'low'],
            tools: $this->tools(),
            messages: $messages,
        );
    }

    /**
     * @return array{0: string, 1: array<int, int>} the tool output, and any listing ids in it
     */
    private function runTool(string $name, array $input): array
    {
        return match ($name) {
            'find_categories' => [$this->findCategories($input['query'] ?? ''), []],
            'search_listings' => $this->searchListings($input),
            default => ['Unknown tool.', []],
        };
    }

    /**
     * Category slugs matching a word or two.
     *
     * The tree has six hundred nodes, which is far too many to put in a
     * system prompt on every request. Letting the model look up the handful
     * it needs costs one extra round trip and keeps the prompt small enough
     * to cache.
     */
    private function findCategories(string $query): string
    {
        $query = trim($query);

        if ($query === '') {
            return "Give a search term, for example 'bass' or 'vinyl'.";
        }

        $matches = Category::query()
            ->where('name', 'like', '%'.Category::escapeLike($query).'%')
            ->orderBy('depth')
            ->orderBy('path')
            ->limit(25)
            ->get(['slug', 'name', 'path', 'is_leaf']);

        if ($matches->isEmpty()) {
            return "No categories match \"{$query}\".";
        }

        return $matches
            ->map(fn (Category $c) => "{$c->slug} — {$c->name} ({$c->path})")
            ->implode("\n");
    }

    /**
     * @return array{0: string, 1: array<int, int>}
     */
    private function searchListings(array $input): array
    {
        $category = isset($input['category'])
            ? Category::where('slug', $input['category'])->first()
            : null;

        if (isset($input['category']) && $category === null) {
            return ["There is no category with the slug \"{$input['category']}\". Use find_categories first.", []];
        }

        $filters = [
            // Only things that can actually be bought. A recommendation
            // nobody can act on is worse than no recommendation.
            'availability' => 'available',
            'brands' => $this->stringList($input['brands'] ?? []),
            'conditions' => $this->stringList($input['conditions'] ?? []),
            'min_price' => $input['min_price'] ?? null,
            'max_price' => $input['max_price'] ?? null,
            'sort' => $input['sort'] ?? 'newest',
        ];

        $filter = new ListingFilter(
            input: $filters,
            category: $category,
            searchIds: null,
            searchTerm: isset($input['query']) ? trim((string) $input['query']) : null,
        );

        $listings = $filter->results(page: 1, viewerId: null)
            ->getCollection()
            ->take(self::SEARCH_LIMIT);

        if ($listings->isEmpty()) {
            return ['No listings match that. Try a wider price range, a broader category, or fewer constraints.', []];
        }

        $lines = $listings->map(function (Listing $listing) {
            $parts = [
                "id={$listing->id}",
                "\"{$listing->title}\"",
                '£'.$listing->price,
                strtolower($listing->condition).' condition',
            ];

            if ($listing->brand) {
                $parts[] = "brand {$listing->brand}";
            }

            $parts[] = 'in '.($listing->category?->name ?? 'uncategorised');
            $parts[] = "located {$listing->location}";

            $attributes = $listing->loadMissing('attributeValues')->attributeMap();

            if ($attributes !== []) {
                $parts[] = collect($attributes)
                    ->map(fn (string $value, string $name) => str_replace('_', ' ', $name).': '.$value)
                    ->implode(', ');
            }

            return '- '.implode(' | ', $parts);
        });

        return [$lines->implode("\n"), $listings->pluck('id')->all()];
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->values()
            ->all();
    }

    private function tools(): array
    {
        return [
            [
                'name' => 'find_categories',
                'description' => 'Look up category slugs by name. The marketplace has a deep tree '
                    .'(for example Guitars > Electric Guitars > Solid Body Electric Guitars), '
                    .'and search_listings needs a slug. Search for a word like "bass", '
                    .'"turntable" or "cable" to find the right one.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'A word or two, e.g. "vinyl" or "amp".'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'search_listings',
                'description' => 'Search what is actually for sale right now. Returns at most '
                    .self::SEARCH_LIMIT.' listings with their id, price, condition and details. '
                    .'Only ever recommend listings this returns.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Free text matched against title, description and brand.',
                        ],
                        'category' => [
                            'type' => 'string',
                            'description' => 'A category slug from find_categories. Searches that category and everything under it.',
                        ],
                        'brands' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Exact brand names, e.g. ["Fender", "Squier"].',
                        ],
                        'conditions' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => ['MINT', 'EXCELLENT', 'GOOD', 'FAIR']],
                        ],
                        'min_price' => ['type' => 'number', 'description' => 'In pounds.'],
                        'max_price' => ['type' => 'number', 'description' => 'In pounds.'],
                        'sort' => [
                            'type' => 'string',
                            'enum' => ['newest', 'price_asc', 'price_desc'],
                            'description' => 'Use price_asc when someone asks for the cheapest.',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are the gear adviser on Restrum, a UK marketplace for secondhand
        musical instruments, hi-fi, vinyl and audio equipment. You help people
        browsing the site work out what to buy.

        How to work:

        - Always search before recommending. Never name a listing you have not
          seen in a search_listings result, and never invent prices, brands or
          specifications. If nothing suitable is for sale, say so plainly and
          suggest what to search for instead, or what to wait for.
        - Use find_categories to turn a plain word into a category slug before
          searching by category.
        - When you recommend a listing, cite it as [[id]] using the id from the
          search result, immediately after naming it. For example:
          "The Squier Telecaster at £220 [[42]] is the obvious starting point."
          Cite at most four listings in a reply.
        - Prices are in pounds. This is a used marketplace, so condition and
          what is included matter as much as the price.

        How to write:

        - Short. Two or three sentences, or a few brief bullets. This is a
          small chat panel beside the listings, not an article.
        - Speak plainly, like a helpful person behind the counter of a music
          shop. No sales patter, no exclamation marks, no "Great question!".
        - Be honest about trade-offs. If the cheap one has a worn stylus or the
          amp is listed as untested, say so. If someone is about to buy more
          guitar than they need, say that too.
        - If the question is not about buying music gear, say that is not
          something you can help with and leave it there.
        PROMPT;
    }

    private function textOf($response): string
    {
        $text = '';

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return trim($text);
    }

    /**
     * The listings the answer actually cites, in the order it cites them.
     *
     * Checked against what search returned rather than trusted: a citation
     * for an id the model never saw is a hallucination, and rendering it as a
     * card would give it the authority of a real listing.
     *
     * @param  array<int, int>  $seen
     * @return array<int, int>
     */
    private function citedIds(string $text, array $seen): array
    {
        preg_match_all('/\[\[(\d+)\]\]/', $text, $matches);

        $cited = [];

        foreach ($matches[1] as $id) {
            $id = (int) $id;

            if (in_array($id, $seen, true) && ! in_array($id, $cited, true)) {
                $cited[] = $id;
            }
        }

        if (count($matches[1]) > count($cited)) {
            // Worth knowing about: it means the prompt is not holding, or the
            // model is citing from memory rather than from search.
            Log::info('Gear adviser cited listings it had not searched.', [
                'cited' => $matches[1],
                'seen' => $seen,
            ]);
        }

        return array_slice($cited, 0, self::MAX_CITED);
    }

    /** The markers are for the widget to resolve, not for a person to read. */
    private function stripCitations(string $text): string
    {
        return trim(preg_replace('/\s*\[\[\d+\]\]/', '', $text));
    }
}
