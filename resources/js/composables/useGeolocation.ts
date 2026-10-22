import { ref } from 'vue';
import type { Ref } from 'vue';
import type { Coordinates } from '@/lib/geo';

export type DevicePosition = Coordinates & {
    accuracy: number;
};

export type GeolocationStatus = 'idle' | 'locating' | 'located' | 'failed';

export type UseGeolocationReturn = {
    status: Ref<GeolocationStatus>;
    position: Ref<DevicePosition | null>;
    errorMessage: Ref<string>;
    locate: () => Promise<DevicePosition | null>;
};

function errorMessageFor(error: GeolocationPositionError): string {
    switch (error.code) {
        case error.PERMISSION_DENIED:
            return 'Location permission was denied. Allow location access for this site in your browser settings and try again.';
        case error.POSITION_UNAVAILABLE:
            return 'Your location is unavailable. Make sure GPS / location services are turned on and try again.';
        case error.TIMEOUT:
            return 'Timed out while getting your location. Move somewhere with a better signal and try again.';
        default:
            return error.message || 'Could not get your location.';
    }
}

/**
 * Read the device position once via the browser Geolocation API.
 */
export function useGeolocation(): UseGeolocationReturn {
    const status = ref<GeolocationStatus>('idle');
    const position = ref<DevicePosition | null>(null);
    const errorMessage = ref('');

    function fail(message: string): null {
        status.value = 'failed';
        errorMessage.value = message;

        return null;
    }

    function locate(): Promise<DevicePosition | null> {
        status.value = 'locating';
        errorMessage.value = '';
        position.value = null;

        if (!window.isSecureContext) {
            return Promise.resolve(
                fail('Location is only available over HTTPS or on localhost.'),
            );
        }

        if (!('geolocation' in navigator)) {
            return Promise.resolve(
                fail('This browser does not support the Geolocation API.'),
            );
        }

        return new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(
                ({ coords }) => {
                    position.value = {
                        latitude: coords.latitude,
                        longitude: coords.longitude,
                        accuracy: coords.accuracy,
                    };
                    status.value = 'located';
                    resolve(position.value);
                },
                (error) => resolve(fail(errorMessageFor(error))),
                { enableHighAccuracy: true, timeout: 15_000, maximumAge: 0 },
            );
        });
    }

    return { status, position, errorMessage, locate };
}
