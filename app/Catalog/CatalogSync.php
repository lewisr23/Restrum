<?php

namespace App\Catalog;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns the Taxonomy array into rows in the `categories` table.
 *
 * Idempotent, and that is the whole design. It runs from the migration that
 * creates the table, from `php artisan catalog:sync` after somebody edits the
 * tree, and from the test suite's seeder, and all three have to end in the
 * same state. So it upserts by path rather than inserting, and never deletes
 * a category that has listings filed under it.
 *
 * Paths are the identity here, not ids. A path is built from the slugs of
 * every ancestor, so "guitars/effects-pedals/delay-pedals" says both what a
 * thing is and where it sits, and a single `path LIKE 'guitars/%'` finds
 * everything under Guitars without a recursive query.
 */
class CatalogSync
{
    /** @var array<string, true> slugs already handed out, for disambiguation */
    private array $usedSlugs = [];

    /** @return array{created: int, updated: int, orphaned: array<int, string>} */
    public function run(): array
    {
        $this->usedSlugs = [];

        $rows = [];
        $this->flatten(Taxonomy::tree(), parentPath: '', depth: 0, rows: $rows);

        $created = 0;
        $updated = 0;

        // One transaction: a half-applied taxonomy is a site where some
        // categories have moved and others have not, which is worse than one
        // that failed cleanly.
        DB::transaction(function () use ($rows, &$created, &$updated) {
            // Parents before children, so a child's parent_id can always be
            // resolved from a row that already exists. The flatten below emits
            // them in that order, and sorting by depth makes that explicit
            // rather than a property of the traversal nobody wrote down.
            usort($rows, static fn (array $a, array $b) => $a['depth'] <=> $b['depth']);

            foreach ($rows as $row) {
                $parentId = $row['parent_path'] === null
                    ? null
                    : Category::where('path', $row['parent_path'])->value('id');

                $existing = Category::where('path', $row['path'])->first();

                if ($existing === null) {
                    Category::create([
                        'parent_id' => $parentId,
                        'slug' => $row['slug'],
                        'path' => $row['path'],
                        'name' => $row['name'],
                        'depth' => $row['depth'],
                        'position' => $row['position'],
                        'is_leaf' => $row['is_leaf'],
                    ]);
                    $created++;

                    continue;
                }

                $existing->fill([
                    'parent_id' => $parentId,
                    'slug' => $row['slug'],
                    'name' => $row['name'],
                    'depth' => $row['depth'],
                    'position' => $row['position'],
                    'is_leaf' => $row['is_leaf'],
                ]);

                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                }
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'orphaned' => $this->orphans(array_column($rows, 'path')),
        ];
    }

    /**
     * Categories in the database that the taxonomy no longer describes.
     *
     * Reported rather than deleted. A category with listings under it cannot
     * simply vanish, and one without them is still a URL somebody may have
     * bookmarked, so the decision belongs to a person.
     *
     * @param  array<int, string>  $knownPaths
     * @return array<int, string>
     */
    private function orphans(array $knownPaths): array
    {
        return Category::whereNotIn('path', $knownPaths)
            ->orderBy('path')
            ->pluck('path')
            ->all();
    }

    /**
     * Walks the nested array into flat rows.
     *
     * @param  array<mixed>  $nodes
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function flatten(array $nodes, string $parentPath, int $depth, array &$rows): void
    {
        $position = 0;

        foreach ($nodes as $key => $value) {
            // A leaf is a plain string, so PHP gives it an integer key. A
            // branch is a name pointing at an array of children.
            $isLeaf = is_string($value);
            $name = $isLeaf ? $value : (string) $key;
            $children = $isLeaf ? [] : $value;

            $slug = $this->uniqueSlug($name, $parentPath);
            $path = $parentPath === '' ? $slug : $parentPath.'/'.$slug;

            $rows[] = [
                'parent_path' => $parentPath === '' ? null : $parentPath,
                'slug' => $slug,
                'path' => $path,
                'name' => $name,
                'depth' => $depth,
                'position' => $position++,
                'is_leaf' => $children === [],
            ];

            if ($children !== []) {
                $this->flatten($children, $path, $depth + 1, $rows);
            }
        }
    }

    /**
     * A slug that no other category is using.
     *
     * Names repeat across branches on purpose: "Power Amplifiers" is a real
     * category under both live sound and hi-fi, and renaming one of them to
     * make a slug unique would be letting the URL scheme dictate the shop.
     * So the second one takes its parent's slug as a prefix, which reads
     * fine: hi-fi-amplifiers-power-amplifiers.
     */
    private function uniqueSlug(string $name, string $parentPath): string
    {
        $base = Str::slug($name);

        // Str::slug strips characters it cannot transliterate, and a name made
        // entirely of them would slug to an empty string.
        if ($base === '') {
            $base = 'category';
        }

        if (! isset($this->usedSlugs[$base])) {
            $this->usedSlugs[$base] = true;

            return $base;
        }

        $parentSlug = $parentPath === '' ? '' : Str::afterLast($parentPath, '/');
        $candidate = $parentSlug === '' ? $base : $parentSlug.'-'.$base;

        $suffix = 2;
        while (isset($this->usedSlugs[$candidate])) {
            $candidate = ($parentSlug === '' ? $base : $parentSlug.'-'.$base).'-'.$suffix;
            $suffix++;
        }

        $this->usedSlugs[$candidate] = true;

        return $candidate;
    }
}
