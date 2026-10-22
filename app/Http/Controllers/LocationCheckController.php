<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Inertia\Inertia;
use Inertia\Response;

class LocationCheckController extends Controller
{
    /**
     * Radius presets in meters offered by the range selector.
     *
     * @var list<int>
     */
    public const RADIUS_PRESETS = [50, 100, 200, 500, 1000, 5000];

    /**
     * Show the location range validation page.
     */
    public function __invoke(): Response
    {
        return Inertia::render('LocationCheck', [
            'locations' => Location::query()->ordered()->get(),
            'radiusPresets' => self::RADIUS_PRESETS,
            'radiusLimits' => [
                'min' => Location::MIN_RADIUS,
                'max' => Location::MAX_RADIUS,
            ],
        ]);
    }
}
