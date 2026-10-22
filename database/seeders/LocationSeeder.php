<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Seed the original hardcoded range check target.
     */
    public function run(): void
    {
        Location::firstOrCreate(
            ['name' => 'Default target'],
            [
                'latitude' => 13.782841584414474,
                'longitude' => 100.63689980346282,
                'default_radius' => 200,
            ],
        );
    }
}
