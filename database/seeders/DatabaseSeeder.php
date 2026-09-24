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
     */
    public function run(): void
    {
        // Demo login, per docs/SETUP.md.
        User::firstOrCreate(
            ['email' => 'admin@billcycle.demo'],
            ['name' => 'Admin', 'password' => 'password'],
        );

        $this->call(DemoSeeder::class);
    }
}
