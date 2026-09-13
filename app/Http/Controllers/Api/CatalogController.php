<?php

namespace App\Http\Controllers\Api;

use App\Catalog\Brands;
use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The shape of the shop: the category tree, the brand lists, and what each
 * category can be filtered by.
 *
 * Served rather than baked into the frontend bundle, because the taxonomy is
 * data that changes without a deploy of the React app, and two copies of a
 * four hundred node tree is one copy that is out of date.
 */
class CatalogController extends Controller
{
    /**
     * How long the assembled tree is cached for.
     *
     * It changes when somebody runs catalog:sync, which is roughly never, so
     * this could be far longer. An hour is short enough that a forgotten
     * cache flush after a taxonomy edit is an hour's confusion rather than a
     * permanent one.
     */
    private const CACHE_MINUTES = 60;

    public const CACHE_KEY = 'catalog.tree';

    public function index(): JsonResponse
    {
        $payload = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => [
                'categories' => $this->tree(),
                'brands' => Brands::byDepartment(),
            ],
        );

        return response()->json($payload);
    }

    /**
     * One category, with everything needed to list something in it.
     *
     * The create and edit forms ask for this the moment a seller picks a
     * category, which is why the filter definitions and the department's
     * brands come back together: they are the rest of the form.
     */
    public function show(Category $category): JsonResponse
    {
        $ancestors = Category::whereIn('path', $category->pathSegments())
            ->orderBy('depth')
            ->get(['slug', 'name', 'depth']);

        return response()->json([
            'slug' => $category->slug,
            'path' => $category->path,
            'name' => $category->name,
            'depth' => $category->depth,
            'is_leaf' => $category->is_leaf,
            'breadcrumbs' => $ancestors->map(fn (Category $c) => [
                'slug' => $c->slug,
                'name' => $c->name,
            ])->all(),
            'brands' => Brands::forDepartment($category->departmentSlug()),
            'attributes' => collect($category->facets())
                ->map(fn (array $definition, string $name) => [
                    'name' => $name,
                    'label' => $definition['label'],
                    'options' => $definition['options'],
                    'help' => $definition['help'] ?? null,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * The whole tree, nested, in one query.
     *
     * Every row is read once and the nesting is assembled in PHP by parent
     * id. The obvious alternative, a recursive load of children, is one query
     * per branch and there are well over a hundred branches.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tree(): array
    {
        $categories = Category::orderBy('depth')->orderBy('position')->get();

        $byParent = $categories->groupBy('parent_id');

        $build = function (?int $parentId) use (&$build, $byParent): array {
            return $byParent->get($parentId, collect())
                ->map(fn (Category $category) => [
                    'slug' => $category->slug,
                    'path' => $category->path,
                    'name' => $category->name,
                    'depth' => $category->depth,
                    'is_leaf' => $category->is_leaf,
                    'children' => $build($category->id),
                ])
                ->values()
                ->all();
        };

        return $build(null);
    }
}
