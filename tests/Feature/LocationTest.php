<?php

use App\Models\Location;

test('locations can be created, updated and deleted', function () {
    $this->postJson(route('locations.store'), [
        'name' => 'Office',
        'latitude' => 13.782841584414474,
        'longitude' => 100.63689980346282,
        'default_radius' => 200,
    ])
        ->assertCreated()
        ->assertJsonPath('location.name', 'Office')
        ->assertJsonPath('locations.0.default_radius', 200);

    $location = Location::sole();

    expect($location->latitude)->toEqualWithDelta(13.7828416, 0.0000001)
        ->and($location->longitude)->toEqualWithDelta(100.6368998, 0.0000001);

    $this->putJson(route('locations.update', $location), [
        'name' => 'Warehouse',
        'latitude' => -33.8688,
        'longitude' => 151.2093,
        'default_radius' => 500,
    ])
        ->assertOk()
        ->assertJsonPath('locations.0.name', 'Warehouse');

    expect($location->fresh())
        ->name->toBe('Warehouse')
        ->default_radius->toBe(500);

    $this->deleteJson(route('locations.destroy', $location))
        ->assertOk()
        ->assertJsonCount(0, 'locations');

    expect(Location::count())->toBe(0);
});

test('a location requires valid coordinates and radius', function (array $overrides, string $invalidField) {
    $this->postJson(route('locations.store'), [
        'name' => 'Office',
        'latitude' => 13.78,
        'longitude' => 100.63,
        'default_radius' => 200,
        ...$overrides,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($invalidField);

    expect(Location::count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'latitude above 90' => [['latitude' => 90.1], 'latitude'],
    'latitude below -90' => [['latitude' => -90.1], 'latitude'],
    'non-numeric latitude' => [['latitude' => 'north'], 'latitude'],
    'longitude above 180' => [['longitude' => 180.1], 'longitude'],
    'longitude below -180' => [['longitude' => -180.1], 'longitude'],
    'zero radius' => [['default_radius' => 0], 'default_radius'],
    'radius above maximum' => [['default_radius' => Location::MAX_RADIUS + 1], 'default_radius'],
    'fractional radius' => [['default_radius' => 12.5], 'default_radius'],
]);

test('updating a location validates its fields', function () {
    $location = Location::factory()->create();

    $this->putJson(route('locations.update', $location), ['name' => 'Only a name'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude', 'longitude', 'default_radius']);
});
