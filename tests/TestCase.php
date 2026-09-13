<?php

namespace Tests;

use App\Models\Category;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The id of a category, by slug.
     *
     * Here rather than in each test because the category tree is reference
     * data created by a migration, so its ids are not knowable in advance and
     * every test that files a listing somewhere specific needs to look one
     * up. Throwing on a miss beats a null foreign key and a confusing failure
     * three assertions later.
     */
    protected function categoryId(string $slug): int
    {
        $id = Category::where('slug', $slug)->value('id');

        if ($id === null) {
            throw new RuntimeException("No category with slug '{$slug}'. Has the taxonomy changed?");
        }

        return $id;
    }
}
