<?php

namespace App\Services\Drafting;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Messages\ToolUseBlock;
use App\Catalog\Brands;
use App\Catalog\Facets;
use App\Models\Category;
use Illuminate\Support\Facades\Log;

/**
 * Photo to listing: Claude looks at the seller's photos and fills in the sell
 * form for them.
 *
 * The point is the seller, not the buyer. A marketplace with no listings has
 * nothing to offer anybody, and the sell form (a six hundred node category
 * tree, per-category fields, a price to pick) is where people give up. This
 * turns "photograph it, check it, post it" into the whole job.
 *
 * Everything that comes back is a suggestion in an ordinary editable form,
 * never a listing. And everything that comes back is checked against the
 * same rules the listing endpoint enforces, so a draft can be wrong but it
 * cannot be invalid: an invented category, a brand not in the list, or a
 * field option that does not exist is dropped here rather than surfacing
 * later as a validation error the seller did not cause.
 *
 * Like the gear adviser, Claude does not get the category tree up front. It
 * searches for the part it needs, which keeps the prompt small enough to
 * cache and means it can only ever name categories that exist.
 */
class ListingDrafter
{
    public const CONDITIONS = ['MINT', 'EXCELLENT', 'GOOD', 'FAIR'];

    /** How many leaf categories one search returns. */
    private const CATEGORY_LIMIT = 12;

    /** Searches before Claude must commit to a draft. Each round is billed. */
    private const MAX_ROUNDS = 4;

    public function __construct(private readonly ?Client $client) {}

    public function isConfigured(): bool
    {
        return $this->client !== null;
    }

    /**
     * Draft a listing from one to three photos.
     *
     * @param  array<int, array{media_type: string, data: string}>  $images  base64 encoded
     * @return array<string, mixed> the cleaned draft, see clean()
     *
     * @throws DraftUnavailable
     */
    public function draft(array $images): array
    {
        if ($this->client === null) {
            throw new DraftUnavailable('Drafting from photos is not configured.');
        }

        return $this->clean($this->requestDraft($images));
    }

    /**
     * Ask Claude, and return whatever it passed to submit_draft.
     *
     * Null when it never did: it could not tell what the photos show, or it
     * declined. Both are an honest "no idea", which the seller sees as such.
     *
     * @param  array<int, array{media_type: string, data: string}>  $images
     * @return array<string, mixed>|null
     */
    protected function requestDraft(array $images): ?array
    {
        $content = array_map(static fn (array $image) => [
            'type' => 'image',
            'source' => ['type' => 'base64', 'mediaType' => $image['media_type'], 'data' => $image['data']],
        ], $images);
        $content[] = ['type' => 'text', 'text' => 'Here are the photos. Draft the listing.'];

        $messages = [['role' => 'user', 'content' => $content]];

        try {
            for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
                $response = $this->send($messages, finalRound: $round === self::MAX_ROUNDS - 1);

                if ($response->stopReason !== 'tool_use') {
                    return null;
                }

                $results = [];

                foreach ($response->content as $block) {
                    if (! $block instanceof ToolUseBlock) {
                        continue;
                    }

                    if ($block->name === 'submit_draft') {
                        return (array) $block->input;
                    }

                    $results[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $block->id,
                        'content' => $block->name === 'find_categories'
                            ? $this->findCategories((string) ($block->input['query'] ?? ''))
                            : 'Unknown tool.',
                    ];
                }

                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (APIException $e) {
            Log::warning('Listing draft request failed.', ['error' => $e->getMessage()]);

            throw new DraftUnavailable('Could not reach the drafting service.', previous: $e);
        }

        return null;
    }

    private function send(array $messages, bool $finalRound)
    {
        return $this->client->messages->create(
            model: (string) config('services.anthropic.model'),
            maxTokens: 8000,
            system: [[
                'type' => 'text',
                'text' => $this->systemPrompt(),
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            // Medium rather than the adviser's low. Telling a Squier from a
            // Fender by the headstock is exactly the kind of looking that
            // effort buys, and it is paid once per listing, not per chat turn.
            outputConfig: ['effort' => 'medium'],
            // On the last round only the draft is offered, so a model still
            // searching has to commit to its best answer rather than run out
            // of rounds with nothing to show.
            tools: $finalRound ? [$this->submitTool()] : [$this->searchTool(), $this->submitTool()],
            messages: $messages,
        );
    }

    private function systemPrompt(): string
    {
        $conditions = implode(', ', self::CONDITIONS);

        return <<<PROMPT
        You help private sellers on Restrum, a UK marketplace for secondhand musical instruments and audio gear, turn photos of an item into a draft listing. The seller reviews and edits everything before it is posted.

        Look carefully at the photos: logos, headstock shape, control layout, model badges, labels, serial plates. Then use find_categories to find the most specific category that fits (search a word or two, such as "electric guitar", "overdrive", "turntable"), and finish by calling submit_draft exactly once.

        Being wrong costs the seller more than being blank, so:
        - Only name a make and model you can support from what is visible. If you can tell it is a Stratocaster style guitar but not who made it, say that and leave brand empty.
        - serial_number only if a serial is clearly legible in a photo, copied character for character. Otherwise null. Never guess one.
        - condition ({$conditions}) only from wear you can actually see. Photos hide scratches, so lean one step lower when unsure, or use null.
        - Only set category fields you can tell from the photos. Leave the rest out.
        - price_low and price_high are what this usually sells for used in the UK, in whole pounds. Use null for both if you cannot identify it well enough to price.
        - title: what a buyer would search for, such as "Fender Player Stratocaster, Polar White, 2021". No hype words.
        - description: two or three short, factual paragraphs in British English, written as the seller describing their own item. Cover what it is, what is visible about its condition, and what is in the photos. Do not claim anything you cannot see, such as that it works, how it sounds, or its history.
        - notes: one or two sentences to the seller about what they must check or add before posting, for example to confirm it powers on or to photograph the serial.

        If the photos do not show a musical instrument, audio equipment or an accessory for one, call submit_draft with identified set to false and explain in notes.
        PROMPT;
    }

    private function searchTool(): array
    {
        return [
            'name' => 'find_categories',
            'description' => 'Find categories by name. Returns the specific categories a listing can be filed in, '
                .'each with its slug and the optional fields it offers with their allowed values.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'A word or two, e.g. "bass", "fuzz", "cassette deck".'],
                ],
                'required' => ['query'],
            ],
        ];
    }

    private function submitTool(): array
    {
        return [
            'name' => 'submit_draft',
            'description' => 'Submit the finished draft listing. Call exactly once, at the end.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identified' => ['type' => 'boolean', 'description' => 'False if you cannot tell what the item is.'],
                    'title' => ['type' => 'string'],
                    'brand' => ['type' => ['string', 'null']],
                    'category' => ['type' => ['string', 'null'], 'description' => 'A slug returned by find_categories.'],
                    'attributes' => [
                        'type' => 'array',
                        'description' => 'Category fields you can tell from the photos, using the exact field names and values from find_categories.',
                        'items' => [
                            'type' => 'object',
                            'properties' => ['name' => ['type' => 'string'], 'value' => ['type' => 'string']],
                            'required' => ['name', 'value'],
                        ],
                    ],
                    'condition' => ['type' => ['string', 'null'], 'enum' => [...self::CONDITIONS, null]],
                    'description' => ['type' => 'string'],
                    'price_low' => ['type' => ['integer', 'null']],
                    'price_high' => ['type' => ['integer', 'null']],
                    'serial_number' => ['type' => ['string', 'null']],
                    'notes' => ['type' => 'string'],
                ],
                'required' => ['identified', 'title', 'brand', 'category', 'attributes', 'condition', 'description', 'price_low', 'price_high', 'serial_number', 'notes'],
            ],
        ];
    }

    /**
     * Leaf categories matching a search, with the fields each one offers.
     *
     * A match on a branch ("Electric Guitars") returns the leaves beneath it,
     * because a listing can only be filed in a leaf and the branch name is
     * often the word a person, or a model, reaches for first.
     */
    public function findCategories(string $query): string
    {
        $query = trim($query);

        if ($query === '') {
            return 'Give a search term, for example "bass" or "delay".';
        }

        $matches = Category::query()
            ->where('name', 'like', '%'.Category::escapeLike($query).'%')
            ->orderBy('depth')
            ->limit(self::CATEGORY_LIMIT)
            ->get();

        $leaves = collect();

        foreach ($matches as $match) {
            $leaves = $leaves->merge($match->is_leaf
                ? [$match]
                : Category::withinPath($match->path)->where('is_leaf', true)->orderBy('path')->limit(self::CATEGORY_LIMIT)->get());
        }

        $leaves = $leaves->unique('id')->take(self::CATEGORY_LIMIT);

        if ($leaves->isEmpty()) {
            return "No categories match \"{$query}\". Try a broader word.";
        }

        return $leaves->map(function (Category $leaf) {
            $lines = ["{$leaf->slug}: {$leaf->name} ({$leaf->path})"];

            foreach (Facets::forCategoryPath($leaf->path) as $name => $facet) {
                $lines[] = "  {$name} ({$facet['label']}): ".implode(' | ', $facet['options']);
            }

            return implode("\n", $lines);
        })->implode("\n");
    }

    /**
     * Hold the draft to the same rules as a real listing.
     *
     * Anything that would fail validation is dropped rather than passed on,
     * so the form never arrives pre-filled with something it will refuse.
     *
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>
     */
    public function clean(?array $raw): array
    {
        if ($raw === null || ($raw['identified'] ?? false) !== true) {
            return [
                'identified' => false,
                'notes' => $this->text($raw['notes'] ?? null, 500)
                    ?? 'Could not tell what this is from the photos. Try a clearer photo of the whole item, or fill the form in yourself.',
            ];
        }

        $category = null;

        if (is_string($raw['category'] ?? null)) {
            $category = Category::where('slug', $raw['category'])->where('is_leaf', true)->first();
        }

        $attributes = [];

        if ($category !== null && is_array($raw['attributes'] ?? null)) {
            $allowed = Facets::forCategoryPath($category->path);

            foreach ($raw['attributes'] as $pair) {
                $name = $pair['name'] ?? null;
                $value = $pair['value'] ?? null;

                if (is_string($name) && isset($allowed[$name]) && is_string($value) && Facets::isValidValue($name, $value)) {
                    $attributes[$name] = $value;
                }
            }
        }

        [$low, $high] = $this->priceRange($raw['price_low'] ?? null, $raw['price_high'] ?? null);

        return [
            'identified' => true,
            'title' => $this->text($raw['title'] ?? null, 200) ?? '',
            'brand' => $this->brand($raw['brand'] ?? null, $category),
            'category' => $category?->slug,
            'attributes' => $attributes,
            'condition' => in_array($raw['condition'] ?? null, self::CONDITIONS, true) ? $raw['condition'] : null,
            'description' => $this->text($raw['description'] ?? null, 5000) ?? '',
            'price_low' => $low,
            'price_high' => $high,
            'serial_number' => $this->text($raw['serial_number'] ?? null, 100),
            'notes' => $this->text($raw['notes'] ?? null, 500),
        ];
    }

    /**
     * The brand exactly as the brand list spells it, or nothing.
     *
     * Matched against the category's own department when there is one,
     * because that is the list the sell form's brand dropdown shows. A brand
     * from another department would be sent with the listing while the
     * dropdown read "Not stated", which is the seller being misled by the
     * form they are checking.
     */
    private function brand(mixed $brand, ?Category $category): ?string
    {
        if (! is_string($brand) || trim($brand) === '') {
            return null;
        }

        $known = $category !== null ? Brands::forDepartment($category->departmentSlug()) : Brands::all();

        foreach ($known as $name) {
            if (strcasecmp($name, trim($brand)) === 0) {
                return $name;
            }
        }

        return null;
    }

    /** @return array{0: int|null, 1: int|null} */
    private function priceRange(mixed $low, mixed $high): array
    {
        if (! is_numeric($low) || ! is_numeric($high) || $low <= 0 || $high <= 0) {
            return [null, null];
        }

        return [(int) round(min($low, $high)), (int) round(max($low, $high))];
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }
}
