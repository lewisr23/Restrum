<?php

namespace App\Catalog;

use App\Models\Category;
use App\Models\Listing;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Turns a browse request into a page of listings and a set of filter counts.
 *
 * The counts are the part worth explaining. Each facet is counted with every
 * filter applied EXCEPT its own, which is why ticking "Fender" does not
 * collapse the brand list to a single row: the brand panel still shows what
 * you would get by ticking Gibson instead, while the condition panel narrows
 * to Fenders. Counting everything with everything applied is simpler to
 * write and produces a filter panel that dead-ends after the first click.
 *
 * Everything here is one SQL layer deliberately, including when Elasticsearch
 * is doing the text matching. The search cluster supplies candidate ids and
 * MySQL does the rest, so filters and counts always agree with each other and
 * with what is actually on the page.
 */
class ListingFilter
{
    public const PER_PAGE = 24;

    /**
     * How many listings a text search can contribute.
     *
     * Facet counts have to be computed over every match, not over one page of
     * them, so the ids all have to come back at once. A cap is the price of
     * that: past this many matches the counts describe the most relevant
     * slice rather than the whole set. At this marketplace's size nothing
     * comes close, and the alternative is moving faceting into Elasticsearch
     * and keeping two filter implementations in step forever.
     */
    public const SEARCH_ID_CAP = 1000;

    /** @var array<int, int>|null memoised, see categoryIds() */
    private ?array $categoryIds = null;

    /**
     * @param  array<string, mixed>  $input  validated query parameters
     * @param  int[]|null  $searchIds  ids from the search cluster, or null when it did not answer
     * @param  string|null  $searchTerm  the raw term, used only when $searchIds is null
     */
    public function __construct(
        private readonly array $input,
        private readonly ?Category $category,
        private readonly ?array $searchIds = null,
        private readonly ?string $searchTerm = null,
    ) {}

    public function results(int $page, ?int $viewerId): LengthAwarePaginator
    {
        $query = $this->constrained(exclude: null)
            ->with(['seller', 'media', 'category'])
            ->when($viewerId, fn (Builder $q) => $q->with([
                'savedBy' => fn ($s) => $s->where('users.id', $viewerId),
            ]));

        $this->applyOrder($query);

        return $query->paginate(self::PER_PAGE, ['*'], 'page', $page);
    }

    /**
     * Everything the filter panel needs to draw itself.
     *
     * @return array<string, mixed>
     */
    public function facets(): array
    {
        return [
            'subcategories' => $this->subcategoryCounts(),
            'brands' => $this->countColumn('brand', exclude: 'brands'),
            'conditions' => $this->countColumn('condition', exclude: 'conditions'),
            'locations' => $this->countColumn('location', exclude: 'locations'),
            'attributes' => $this->attributeCounts(),
            'price' => $this->priceBounds(),
            'total' => $this->constrained(exclude: null)->count(),
        ];
    }

    /**
     * The base query with every filter applied except the named one.
     *
     * `exclude` is the facet currently being counted. Passing null applies
     * everything, which is what the results themselves need.
     */
    private function constrained(?string $exclude): Builder
    {
        $query = Listing::query();

        // The text search has already happened, in Elasticsearch. Its answer
        // is a set of ids, and an empty set is a real answer meaning "nothing
        // matched" rather than "no search", so it must not be skipped.
        if ($this->searchIds !== null) {
            $query->whereIn('listings.id', $this->searchIds);
        } elseif (($this->searchTerm ?? '') !== '') {
            // No cluster, so the SQL fallback the site has always had. It
            // cannot use an index and it matches substrings rather than
            // words, which is why it is the fallback and not the plan, but
            // browsing has to keep working when the cluster does not.
            $term = '%'.Category::escapeLike($this->searchTerm).'%';

            $query->where(function (Builder $inner) use ($term) {
                $inner->where('listings.title', 'like', $term)
                    ->orWhere('listings.description', 'like', $term)
                    ->orWhere('listings.brand', 'like', $term);
            });
        }

        if ($this->category !== null) {
            $query->whereIn('listings.category_id', $this->categoryIds());
        }

        if ($exclude !== 'availability' && ($this->input['availability'] ?? null) === 'available') {
            $query->where('listings.status', 'ACTIVE');
        }

        if ($exclude !== 'brands' && $this->values('brands') !== []) {
            $query->whereIn('listings.brand', $this->values('brands'));
        }

        if ($exclude !== 'conditions' && $this->values('conditions') !== []) {
            $query->whereIn('listings.condition', $this->values('conditions'));
        }

        if ($exclude !== 'locations' && $this->values('locations') !== []) {
            $query->whereIn('listings.location', $this->values('locations'));
        }

        if ($exclude !== 'price') {
            if (($this->input['min_price'] ?? null) !== null) {
                $query->where('listings.price', '>=', $this->input['min_price']);
            }

            if (($this->input['max_price'] ?? null) !== null) {
                $query->where('listings.price', '<=', $this->input['max_price']);
            }
        }

        foreach ($this->attributeFilters() as $name => $values) {
            // The excluded attribute is named with a prefix so it cannot
            // collide with 'brands' or any other facet key.
            if ($exclude === 'attr:'.$name) {
                continue;
            }

            // EXISTS rather than a join. A join would multiply the listing
            // rows by the number of matching attribute rows and every count
            // after it would be wrong.
            $query->whereExists(function (QueryBuilder $sub) use ($name, $values) {
                $sub->from('listing_attributes')
                    ->whereColumn('listing_attributes.listing_id', 'listings.id')
                    ->where('listing_attributes.name', $name)
                    ->whereIn('listing_attributes.value', $values);
            });
        }

        return $query;
    }

    /**
     * How many listings sit under each child of the category being browsed.
     *
     * One query rather than one per child. SUBSTRING_INDEX trims each
     * listing's category path back to the level below the current one, so
     * grouping by it counts a whole branch at a time: browsing Guitars, a
     * listing filed under electric guitars/solid body counts towards
     * "Electric Guitars".
     *
     * This is MySQL specific, which the rest of this project already is on
     * purpose (see the note in phpunit.xml about enum columns and clocks).
     *
     * @return array<int, array<string, mixed>>
     */
    private function subcategoryCounts(): array
    {
        $depth = $this->category === null ? 0 : $this->category->depth + 1;
        $segments = $depth + 1;

        $counts = $this->constrained(exclude: null)
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->groupBy('branch')
            ->selectRaw('SUBSTRING_INDEX(categories.path, "/", ?) as branch, COUNT(*) as total', [$segments])
            ->pluck('total', 'branch');

        if ($counts->isEmpty()) {
            return [];
        }

        // The children themselves come from the tree rather than from the
        // counts, so a branch with nothing in it is still listed, at zero.
        // A category that vanishes when empty is a category nobody can be the
        // first to list in.
        $children = Category::query()
            ->when($this->category === null, fn ($q) => $q->whereNull('parent_id'))
            ->when($this->category !== null, fn ($q) => $q->where('parent_id', $this->category->id))
            ->orderBy('position')
            ->get();

        return $children->map(fn (Category $child) => [
            'slug' => $child->slug,
            'path' => $child->path,
            'name' => $child->name,
            'is_leaf' => $child->is_leaf,
            'count' => (int) ($counts[$child->path] ?? 0),
        ])->all();
    }

    /**
     * Counts for a plain column on the listings table.
     *
     * $column is interpolated rather than bound, which is safe here and only
     * here: every caller passes a literal from the facets() method above, and
     * a column name cannot be a bound parameter in SQL anyway. Nothing from a
     * request reaches it.
     *
     * Ordered by count so the brands people are actually selling come first,
     * then alphabetically so the long tail is scannable.
     *
     * @return array<int, array{value: string, count: int}>
     */
    private function countColumn(string $column, string $exclude): array
    {
        return $this->constrained($exclude)
            ->whereNotNull("listings.{$column}")
            ->where("listings.{$column}", '!=', '')
            ->groupBy("listings.{$column}")
            ->selectRaw("listings.{$column} as value, COUNT(*) as total")
            ->orderByDesc('total')
            ->orderBy('value')
            ->get()
            ->map(fn ($row) => ['value' => (string) $row->value, 'count' => (int) $row->total])
            ->all();
    }

    /**
     * Counts for every attribute that applies to the category being browsed.
     *
     * Split into two passes for a reason that is purely about query count.
     * Attributes the buyer has not filtered on all share one base query, so
     * they can be counted together in a single grouped query. Only the ones
     * actually being filtered need a query of their own, because each has to
     * exclude itself. On a first page load, where nothing is ticked, that is
     * one query for the whole panel instead of eighty.
     *
     * @return array<string, array<int, array{value: string, count: int}>>
     */
    private function attributeCounts(): array
    {
        $applicable = $this->category === null
            ? []
            : array_keys(Facets::forCategoryPath($this->category->path));

        if ($applicable === []) {
            return [];
        }

        $selected = array_keys($this->attributeFilters());
        $unselected = array_values(array_diff($applicable, $selected));

        $counts = [];

        if ($unselected !== []) {
            $rows = $this->constrained(exclude: null)
                ->join('listing_attributes', 'listing_attributes.listing_id', '=', 'listings.id')
                ->whereIn('listing_attributes.name', $unselected)
                ->groupBy('listing_attributes.name', 'listing_attributes.value')
                ->selectRaw('listing_attributes.name, listing_attributes.value, COUNT(*) as total')
                ->get();

            foreach ($rows as $row) {
                $counts[$row->name][] = ['value' => $row->value, 'count' => (int) $row->total];
            }
        }

        foreach (array_intersect($applicable, $selected) as $name) {
            $rows = $this->constrained(exclude: 'attr:'.$name)
                ->join('listing_attributes', 'listing_attributes.listing_id', '=', 'listings.id')
                ->where('listing_attributes.name', $name)
                ->groupBy('listing_attributes.value')
                ->selectRaw('listing_attributes.value, COUNT(*) as total')
                ->get();

            foreach ($rows as $row) {
                $counts[$name][] = ['value' => $row->value, 'count' => (int) $row->total];
            }
        }

        // Ordered by the options list rather than by count, so a scale reads
        // in its own order: 7", 10", 12" and not 12", 7", 10". A size filter
        // sorted by popularity is a size filter nobody can scan.
        $definitions = Facets::definitions();
        $ordered = [];

        foreach ($applicable as $name) {
            if (! isset($counts[$name])) {
                continue;
            }

            $order = array_flip($definitions[$name]['options']);
            $values = $counts[$name];

            usort($values, static fn (array $a, array $b) => ($order[$a['value']] ?? PHP_INT_MAX) <=> ($order[$b['value']] ?? PHP_INT_MAX));

            $ordered[$name] = $values;
        }

        return $ordered;
    }

    /**
     * The cheapest and dearest thing matching everything except the price
     * filter, so the price inputs can show the range they are working within.
     *
     * @return array{min: ?float, max: ?float}
     */
    private function priceBounds(): array
    {
        $bounds = $this->constrained(exclude: 'price')
            ->selectRaw('MIN(listings.price) as low, MAX(listings.price) as high')
            ->first();

        return [
            'min' => $bounds?->low === null ? null : (float) $bounds->low,
            'max' => $bounds?->high === null ? null : (float) $bounds->high,
        ];
    }

    /**
     * The ids of the category being browsed and everything under it.
     *
     * Resolved once and reused across the dozen or so aggregate queries a
     * facet panel costs, rather than repeating the subquery in each. Cached
     * on the instance rather than in a static, which would be shared between
     * every filter in the process and hand one request another's category.
     *
     * @return array<int, int>
     */
    private function categoryIds(): array
    {
        return $this->categoryIds ??= Category::query()
            ->withinPath($this->category->path)
            ->pluck('id')
            ->all();
    }

    /** @return array<string, array<int, string>> */
    private function attributeFilters(): array
    {
        $filters = [];

        foreach ($this->input['attributes'] ?? [] as $name => $values) {
            $values = array_values(array_filter((array) $values, static fn ($v) => $v !== null && $v !== ''));

            if ($values !== []) {
                $filters[$name] = $values;
            }
        }

        return $filters;
    }

    /** @return array<int, string> */
    private function values(string $key): array
    {
        return array_values(array_filter((array) ($this->input[$key] ?? []), static fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Sold listings sink to the bottom whatever the sort, because a page of
     * things you cannot buy, sorted beautifully, is still a page of things
     * you cannot buy.
     *
     * Every sort ends on id descending. Without a deterministic tiebreak,
     * two listings at the same price can swap places between page one and
     * page two, and the same listing appears twice or not at all.
     */
    private function applyOrder(Builder $query): void
    {
        $query->orderByRaw("listings.status = 'SOLD'");

        // Relevance is only meaningful when something ranked the results, so
        // it is the default with a search term and unavailable without one.
        $sort = $this->input['sort'] ?? ($this->searchIds === null ? 'newest' : 'relevance');

        match ($sort) {
            'price_asc' => $query->orderBy('listings.price')->orderByDesc('listings.id'),
            'price_desc' => $query->orderByDesc('listings.price')->orderByDesc('listings.id'),
            'relevance' => $this->applyRelevanceOrder($query),
            default => $query->orderByDesc('listings.created_at')->orderByDesc('listings.id'),
        };
    }

    /**
     * Puts the rows back into the order the search cluster ranked them.
     *
     * MySQL has no memory of that ordering, so it is reapplied with FIELD()
     * over the id list. The ids are integers cast from Elasticsearch's own
     * document ids, so there is nothing here that came from a request as
     * text, but they are bound as parameters regardless.
     */
    private function applyRelevanceOrder(Builder $query): void
    {
        if ($this->searchIds === null || $this->searchIds === []) {
            $query->orderByDesc('listings.created_at')->orderByDesc('listings.id');

            return;
        }

        $placeholders = implode(',', array_fill(0, count($this->searchIds), '?'));

        $query->orderByRaw("FIELD(listings.id, {$placeholders})", $this->searchIds)
            ->orderByDesc('listings.id');
    }
}
