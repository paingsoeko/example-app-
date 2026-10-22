<?php

use App\Models\Location;
use Inertia\Testing\AssertableInertia as Assert;

test('the location check page renders saved locations and radius settings', function () {
    Location::factory()->create(['name' => 'Zulu']);
    Location::factory()->create(['name' => 'Alpha']);

    $this->get(route('location-check'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LocationCheck')
            ->has('locations', 2)
            ->where('locations.0.name', 'Alpha')
            ->where('radiusPresets', fn ($presets) => collect($presets)->contains(200))
            ->where('radiusLimits.min', Location::MIN_RADIUS)
            ->where('radiusLimits.max', Location::MAX_RADIUS)
        );
});
