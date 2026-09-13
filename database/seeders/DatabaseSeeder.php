<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * The category tree is NOT seeded here. It is reference data the
     * application cannot serve a page without, so it is created by the
     * migration that makes the table, and reapplied with `catalog:sync`.
     * Only sample data belongs in a seeder.
     */
    public function run(): void
    {
        // This used to set a `name` column, which this application's users
        // table has never had: it was Laravel's default seeder, left as
        // shipped, and it would have thrown the first time anyone ran it.
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'username' => 'testuser',
                'password' => bcrypt('password'),
                'location' => 'Newcastle',
                'email_verified_at' => now(),
            ],
        );

        $this->call(DemoListingsSeeder::class);
    }
}
