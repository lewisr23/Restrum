<?php

namespace App\Models;

use App\Catalog\Facets;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One node of the category tree.
 *
 * Written by CatalogSync from the Taxonomy array and never by request input,
 * which is why everything is fillable: the only thing that fills it is the
 * sync, and it is not reachable from a controller.
 *
 * The `path` column is what makes this cheap to query. It holds every
 * ancestor's slug joined by slashes, so "everything under Guitars" is a LIKE
 * on an indexed column rather than a recursive walk, and a category knows
 * where it sits without loading its parents.
 */
#[Fillable(['parent_id', 'slug', 'path', 'name', 'depth', 'position', 'is_leaf'])]
class Category extends Model
{
    protected function casts(): array
    {
        return [
            'is_leaf' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /** Route model binding by slug, since ids mean nothing in a URL. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * This category and everything beneath it.
     *
     * The one query the whole browse experience is built on. Escaping the
     * wildcards matters even though our own slugs never contain them: the
     * value reaching here came from a query string.
     */
    public function scopeWithinPath(Builder $query, string $path): Builder
    {
        return $query->where(function (Builder $inner) use ($path) {
            $inner->where('path', $path)
                ->orWhere('path', 'like', self::escapeLike($path).'/%');
        });
    }

    /** The department this category belongs to, which is its first segment. */
    public function departmentSlug(): string
    {
        return Str::before($this->path, '/');
    }

    /** The paths of every ancestor, nearest last, including this category. */
    public function pathSegments(): array
    {
        $segments = explode('/', $this->path);
        $paths = [];
        $running = '';

        foreach ($segments as $segment) {
            $running = $running === '' ? $segment : $running.'/'.$segment;
            $paths[] = $running;
        }

        return $paths;
    }

    /** The filters that make sense here. See App\Catalog\Facets. */
    public function facets(): array
    {
        return Facets::forCategoryPath($this->path);
    }

    /**
     * LIKE treats % and _ as wildcards, so a path containing either would
     * match more than itself. Ours never do, but this value arrives from a
     * request and "our data is fine" is not a thing to rely on where a
     * wildcard could widen a query.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
