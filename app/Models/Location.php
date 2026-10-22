<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property float $latitude
 * @property float $longitude
 * @property int $default_radius
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'latitude', 'longitude', 'default_radius'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * Smallest radius in meters a location or range check may use.
     */
    public const MIN_RADIUS = 1;

    /**
     * Largest radius in meters a location or range check may use.
     */
    public const MAX_RADIUS = 100_000;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'default_radius' => 'integer',
        ];
    }

    /**
     * Order locations alphabetically by name.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('name')->orderBy('id');
    }
}
