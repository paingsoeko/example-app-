export type Coordinates = {
    latitude: number;
    longitude: number;
};

const EARTH_RADIUS_METERS = 6_371_000;

function toRadians(degrees: number): number {
    return (degrees * Math.PI) / 180;
}

/**
 * Great-circle distance in meters between two points (Haversine formula).
 */
export function haversineDistance(from: Coordinates, to: Coordinates): number {
    const deltaLatitude = toRadians(to.latitude - from.latitude);
    const deltaLongitude = toRadians(to.longitude - from.longitude);

    const a =
        Math.sin(deltaLatitude / 2) ** 2 +
        Math.cos(toRadians(from.latitude)) *
            Math.cos(toRadians(to.latitude)) *
            Math.sin(deltaLongitude / 2) ** 2;

    return 2 * EARTH_RADIUS_METERS * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

const COORDINATE_PAIR_PATTERN =
    /^\s*\(?\s*(-?\d+(?:\.\d+)?)\s*[,;\s]\s*(-?\d+(?:\.\d+)?)\s*\)?\s*$/;

/**
 * Parse a "latitude, longitude" string such as one copied from Google Maps.
 */
export function parseCoordinatePair(text: string): Coordinates | null {
    const match = COORDINATE_PAIR_PATTERN.exec(text);

    if (!match) {
        return null;
    }

    const latitude = Number(match[1]);
    const longitude = Number(match[2]);

    if (Math.abs(latitude) > 90 || Math.abs(longitude) > 180) {
        return null;
    }

    return { latitude, longitude };
}

/**
 * Format a whole-meter radius, switching to kilometers from 1000 m.
 */
export function formatRadius(meters: number): string {
    return meters >= 1000 ? `${meters / 1000} km` : `${meters} m`;
}
