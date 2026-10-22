<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocationRequest;
use App\Models\Location;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    /**
     * Create a location.
     */
    public function store(LocationRequest $request): JsonResponse
    {
        $location = Location::create($request->validated());

        return response()->json([
            'location' => $location,
            'locations' => Location::query()->ordered()->get(),
        ], 201);
    }

    /**
     * Update a location.
     */
    public function update(LocationRequest $request, Location $location): JsonResponse
    {
        $location->update($request->validated());

        return response()->json([
            'location' => $location,
            'locations' => Location::query()->ordered()->get(),
        ]);
    }

    /**
     * Delete a location.
     */
    public function destroy(Location $location): JsonResponse
    {
        $location->delete();

        return response()->json([
            'locations' => Location::query()->ordered()->get(),
        ]);
    }
}
