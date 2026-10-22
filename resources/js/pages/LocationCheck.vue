<script setup lang="ts">
import { Head, Link, useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    destroy,
    store,
    update,
} from '@/actions/App/Http/Controllers/LocationController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useGeolocation } from '@/composables/useGeolocation';
import {
    formatRadius,
    haversineDistance,
    parseCoordinatePair,
} from '@/lib/geo';
import { home } from '@/routes';

type Location = {
    id: number;
    name: string;
    latitude: number;
    longitude: number;
    default_radius: number;
    created_at: string;
    updated_at: string;
};

type RadiusLimits = {
    min: number;
    max: number;
};

const props = defineProps<{
    locations: Location[];
    radiusPresets: number[];
    radiusLimits: RadiusLimits;
}>();

/**
 * Decode an error response body, which Inertia passes as a raw string.
 */
function errorMessage(rawData: unknown, fallback: string): string {
    let data = rawData;

    if (typeof data === 'string') {
        try {
            data = JSON.parse(data);
        } catch {
            return fallback;
        }
    }

    if (data && typeof data === 'object' && 'message' in data) {
        return String((data as { message: unknown }).message);
    }

    return fallback;
}

/*
 * Saved locations
 */
const locations = ref<Location[]>(props.locations);
const editingLocationId = ref<number | null>(null);
const crudMessage = ref('');
const crudFailed = ref(false);

const locationForm = useHttp<{
    name: string;
    latitude: number | string;
    longitude: number | string;
    default_radius: number | string;
}>({
    name: '',
    latitude: '',
    longitude: '',
    default_radius: 200,
});
const deleteRequest = useHttp({});
const formLocator = useGeolocation();

function reportCrud(message: string, failed = false): void {
    crudMessage.value = message;
    crudFailed.value = failed;
}

function handleLocationsResponse(data: unknown, message: string): void {
    locations.value = (data as { locations: Location[] }).locations;
    reportCrud(message);
}

function handleCrudFailure(response: { data: unknown }): void {
    reportCrud(errorMessage(response.data, 'Request failed.'), true);
}

function resetLocationForm(): void {
    editingLocationId.value = null;
    locationForm.reset();
    locationForm.clearErrors();
}

function startEditing(location: Location): void {
    editingLocationId.value = location.id;
    locationForm.name = location.name;
    locationForm.latitude = location.latitude;
    locationForm.longitude = location.longitude;
    locationForm.default_radius = location.default_radius;
    locationForm.clearErrors();
}

/**
 * Fill both coordinate fields when a "latitude, longitude" pair is pasted into either one.
 */
function pasteCoordinatePair(event: ClipboardEvent): void {
    const coordinates = parseCoordinatePair(
        event.clipboardData?.getData('text') ?? '',
    );

    if (!coordinates) {
        return;
    }

    event.preventDefault();
    locationForm.latitude = coordinates.latitude;
    locationForm.longitude = coordinates.longitude;
    locationForm.clearErrors('latitude', 'longitude');
}

async function fillFormFromDevice(): Promise<void> {
    const position = await formLocator.locate();

    if (position) {
        locationForm.latitude = position.latitude;
        locationForm.longitude = position.longitude;
    }
}

function saveLocation(): void {
    const options = {
        onError: () => reportCrud('Please fix the highlighted fields.', true),
        onHttpException: handleCrudFailure,
    };

    if (editingLocationId.value === null) {
        locationForm.post(store.url(), {
            ...options,
            onSuccess: (data) => {
                handleLocationsResponse(data, 'Location created.');
                selectedLocationId.value = (
                    data as { location: Location }
                ).location.id;
                resetLocationForm();
            },
        });

        return;
    }

    const id = editingLocationId.value;

    locationForm.put(update.url(id), {
        ...options,
        onSuccess: (data) => {
            handleLocationsResponse(data, 'Location updated.');
            resetLocationForm();
        },
    });
}

function deleteLocation(location: Location): void {
    if (!window.confirm(`Delete "${location.name}"?`)) {
        return;
    }

    deleteRequest.delete(destroy.url(location.id), {
        onSuccess: (data) => {
            handleLocationsResponse(data, `"${location.name}" deleted.`);

            if (editingLocationId.value === location.id) {
                resetLocationForm();
            }
        },
        onHttpException: handleCrudFailure,
    });
}

/*
 * Range check
 */
const selectedLocationId = ref<number | null>(locations.value[0]?.id ?? null);
const selectedLocation = computed<Location | null>(
    () =>
        locations.value.find(({ id }) => id === selectedLocationId.value) ??
        null,
);

const selectedRadii = ref<number[]>([]);
const customRadius = ref<number | string>('');
const customRadiusError = ref('');

const radiusChoices = computed<number[]>(() =>
    [...new Set([...props.radiusPresets, ...selectedRadii.value])].sort(
        (a, b) => a - b,
    ),
);

function isRadiusSelected(radius: number): boolean {
    return selectedRadii.value.includes(radius);
}

function toggleRadius(
    radius: number,
    checked: boolean | 'indeterminate',
): void {
    selectedRadii.value =
        checked === true
            ? [...new Set([...selectedRadii.value, radius])]
            : selectedRadii.value.filter((value) => value !== radius);
}

function addCustomRadius(): void {
    const radius = Number(customRadius.value);
    const { min, max } = props.radiusLimits;

    if (!Number.isInteger(radius) || radius < min || radius > max) {
        customRadiusError.value = `Enter a whole number between ${min} and ${max} meters.`;

        return;
    }

    customRadiusError.value = '';
    customRadius.value = '';
    toggleRadius(radius, true);
}

watch(
    () => selectedLocation.value?.default_radius,
    (defaultRadius) => {
        if (defaultRadius !== undefined) {
            toggleRadius(defaultRadius, true);
        }
    },
    { immediate: true },
);

watch(locations, () => {
    if (!selectedLocation.value) {
        selectedLocationId.value = locations.value[0]?.id ?? null;
    }
});

const deviceLocator = useGeolocation();

const distance = computed<number | null>(() =>
    selectedLocation.value && deviceLocator.position.value
        ? haversineDistance(
              selectedLocation.value,
              deviceLocator.position.value,
          )
        : null,
);

const rangeResults = computed(() => {
    const measured = distance.value;

    if (measured === null) {
        return [];
    }

    return [...selectedRadii.value]
        .sort((a, b) => a - b)
        .map((radius) => ({
            radius,
            approved: measured <= radius,
            margin: radius - measured,
        }));
});
</script>

<template>
    <Head title="Location Check" />

    <div class="min-h-screen bg-background px-4 py-10 text-foreground">
        <div class="mx-auto flex max-w-5xl flex-col gap-6">
            <header class="flex flex-col gap-3">
                <Link
                    :href="home()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    ← Feature tests
                </Link>
                <h1 class="text-2xl font-semibold">Location Range Check</h1>
                <p class="text-sm text-muted-foreground">
                    Manage target locations and validate whether this device is
                    within one or more radii of the selected target.
                </p>
            </header>

            <!-- Range check -->
            <Card>
                <CardHeader>
                    <CardTitle>Range check</CardTitle>
                    <CardDescription>
                        Uses the browser Geolocation API. Your browser will ask
                        for permission the first time.
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-6">
                    <p
                        v-if="locations.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No saved locations yet. Add one below to start testing.
                    </p>

                    <template v-else>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="grid content-start gap-1">
                                <Label for="target-location"
                                    >Target location</Label
                                >
                                <select
                                    id="target-location"
                                    v-model.number="selectedLocationId"
                                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                >
                                    <option
                                        v-for="location in locations"
                                        :key="location.id"
                                        :value="location.id"
                                    >
                                        {{ location.name }}
                                    </option>
                                </select>
                                <dl
                                    v-if="selectedLocation"
                                    class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm"
                                >
                                    <dt class="text-muted-foreground">
                                        Latitude
                                    </dt>
                                    <dd class="font-mono break-all">
                                        {{ selectedLocation.latitude }}
                                    </dd>
                                    <dt class="text-muted-foreground">
                                        Longitude
                                    </dt>
                                    <dd class="font-mono break-all">
                                        {{ selectedLocation.longitude }}
                                    </dd>
                                    <dt class="text-muted-foreground">
                                        Default radius
                                    </dt>
                                    <dd>
                                        {{
                                            formatRadius(
                                                selectedLocation.default_radius,
                                            )
                                        }}
                                    </dd>
                                </dl>
                            </div>

                            <fieldset class="grid content-start gap-2">
                                <legend class="mb-1 text-sm font-medium">
                                    Radii to test
                                </legend>
                                <div class="flex flex-wrap gap-x-4 gap-y-2">
                                    <label
                                        v-for="radius in radiusChoices"
                                        :key="radius"
                                        class="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            :model-value="
                                                isRadiusSelected(radius)
                                            "
                                            @update:model-value="
                                                toggleRadius(radius, $event)
                                            "
                                        />
                                        {{ formatRadius(radius) }}
                                    </label>
                                </div>
                                <form
                                    class="mt-1 flex gap-2"
                                    @submit.prevent="addCustomRadius"
                                >
                                    <Input
                                        v-model="customRadius"
                                        type="number"
                                        inputmode="numeric"
                                        :min="radiusLimits.min"
                                        :max="radiusLimits.max"
                                        placeholder="Custom radius (m)"
                                        aria-label="Custom radius in meters"
                                        class="max-w-48"
                                    />
                                    <Button type="submit" variant="outline">
                                        Add
                                    </Button>
                                </form>
                                <InputError :message="customRadiusError" />
                            </fieldset>
                        </div>

                        <div class="flex flex-col gap-4">
                            <Button
                                class="self-start"
                                :disabled="
                                    deviceLocator.status.value === 'locating'
                                "
                                @click="deviceLocator.locate"
                            >
                                {{
                                    deviceLocator.status.value === 'locating'
                                        ? 'Getting location…'
                                        : 'Get current location'
                                }}
                            </Button>

                            <p
                                v-if="deviceLocator.status.value === 'failed'"
                                role="alert"
                                class="rounded-md border border-destructive/50 px-3 py-2 text-sm text-destructive"
                            >
                                {{ deviceLocator.errorMessage.value }}
                            </p>

                            <template
                                v-if="
                                    deviceLocator.position.value &&
                                    distance !== null
                                "
                            >
                                <dl
                                    class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm"
                                >
                                    <dt class="text-muted-foreground">
                                        Your latitude
                                    </dt>
                                    <dd class="font-mono break-all">
                                        {{
                                            deviceLocator.position.value
                                                .latitude
                                        }}
                                    </dd>
                                    <dt class="text-muted-foreground">
                                        Your longitude
                                    </dt>
                                    <dd class="font-mono break-all">
                                        {{
                                            deviceLocator.position.value
                                                .longitude
                                        }}
                                    </dd>
                                    <dt class="text-muted-foreground">
                                        Accuracy
                                    </dt>
                                    <dd>
                                        ±
                                        {{
                                            Math.round(
                                                deviceLocator.position.value
                                                    .accuracy,
                                            )
                                        }}
                                        m
                                    </dd>
                                    <dt class="text-muted-foreground">
                                        Distance
                                    </dt>
                                    <dd class="font-semibold">
                                        {{ distance.toFixed(2) }} m
                                    </dd>
                                </dl>

                                <p
                                    v-if="rangeResults.length === 0"
                                    class="text-sm text-muted-foreground"
                                >
                                    Select at least one radius to see results.
                                </p>
                                <div
                                    v-else
                                    class="overflow-x-auto rounded-md border"
                                >
                                    <table class="w-full text-sm">
                                        <thead class="bg-muted/50 text-left">
                                            <tr>
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Radius
                                                </th>
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Result
                                                </th>
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                >
                                                    Margin
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="result in rangeResults"
                                                :key="result.radius"
                                                class="border-t"
                                            >
                                                <td class="px-3 py-2">
                                                    {{
                                                        formatRadius(
                                                            result.radius,
                                                        )
                                                    }}
                                                </td>
                                                <td class="px-3 py-2">
                                                    <Badge
                                                        :variant="
                                                            result.approved
                                                                ? 'default'
                                                                : 'destructive'
                                                        "
                                                    >
                                                        {{
                                                            result.approved
                                                                ? 'APPROVED'
                                                                : 'REJECTED'
                                                        }}
                                                    </Badge>
                                                </td>
                                                <td
                                                    class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                                                >
                                                    {{
                                                        result.approved
                                                            ? `${result.margin.toFixed(2)} m to spare`
                                                            : `${(-result.margin).toFixed(2)} m too far`
                                                    }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </template>
                        </div>
                    </template>
                </CardContent>
            </Card>

            <!-- Saved locations -->
            <Card>
                <CardHeader>
                    <CardTitle>Saved locations</CardTitle>
                    <CardDescription>
                        Create, edit and delete the targets available for range
                        checks.
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <form
                        class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        @submit.prevent="saveLocation"
                    >
                        <div class="grid content-start gap-1">
                            <Label for="location-name">Name</Label>
                            <Input
                                id="location-name"
                                v-model="locationForm.name"
                                placeholder="Office entrance"
                            />
                            <InputError :message="locationForm.errors.name" />
                        </div>
                        <div class="grid content-start gap-1">
                            <Label for="location-latitude">Latitude</Label>
                            <Input
                                id="location-latitude"
                                v-model.number="locationForm.latitude"
                                @paste="pasteCoordinatePair"
                                type="number"
                                step="any"
                                min="-90"
                                max="90"
                                inputmode="decimal"
                                placeholder="13.7828416"
                            />
                            <InputError
                                :message="locationForm.errors.latitude"
                            />
                        </div>
                        <div class="grid content-start gap-1">
                            <Label for="location-longitude">Longitude</Label>
                            <Input
                                id="location-longitude"
                                v-model.number="locationForm.longitude"
                                @paste="pasteCoordinatePair"
                                type="number"
                                step="any"
                                min="-180"
                                max="180"
                                inputmode="decimal"
                                placeholder="100.6368998"
                            />
                            <InputError
                                :message="locationForm.errors.longitude"
                            />
                        </div>
                        <div class="grid content-start gap-1">
                            <Label for="location-radius"
                                >Default radius (m)</Label
                            >
                            <Input
                                id="location-radius"
                                v-model.number="locationForm.default_radius"
                                type="number"
                                inputmode="numeric"
                                :min="radiusLimits.min"
                                :max="radiusLimits.max"
                            />
                            <InputError
                                :message="locationForm.errors.default_radius"
                            />
                        </div>

                        <div
                            class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-4"
                        >
                            <Button
                                type="submit"
                                :disabled="locationForm.processing"
                            >
                                {{
                                    editingLocationId === null
                                        ? 'Create location'
                                        : 'Save changes'
                                }}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    formLocator.status.value === 'locating'
                                "
                                @click="fillFormFromDevice"
                            >
                                {{
                                    formLocator.status.value === 'locating'
                                        ? 'Getting location…'
                                        : 'Use my current location'
                                }}
                            </Button>
                            <Button
                                v-if="editingLocationId !== null"
                                type="button"
                                variant="ghost"
                                @click="resetLocationForm"
                            >
                                Cancel
                            </Button>
                        </div>
                        <InputError
                            class="sm:col-span-2 lg:col-span-4"
                            :message="formLocator.errorMessage.value"
                        />
                    </form>

                    <p
                        v-if="crudMessage"
                        class="text-sm"
                        :class="
                            crudFailed
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ crudMessage }}
                    </p>

                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Name</th>
                                    <th class="px-3 py-2 font-medium">
                                        Latitude
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        Longitude
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        Default radius
                                    </th>
                                    <th class="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="locations.length === 0">
                                    <td
                                        colspan="5"
                                        class="px-3 py-6 text-center text-muted-foreground"
                                    >
                                        No locations yet. Create one above.
                                    </td>
                                </tr>
                                <tr
                                    v-for="location in locations"
                                    :key="location.id"
                                    class="border-t"
                                    :class="{
                                        'bg-muted/30':
                                            editingLocationId === location.id,
                                    }"
                                >
                                    <td class="px-3 py-2">
                                        {{ location.name }}
                                    </td>
                                    <td class="px-3 py-2 font-mono">
                                        {{ location.latitude }}
                                    </td>
                                    <td class="px-3 py-2 font-mono">
                                        {{ location.longitude }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        {{
                                            formatRadius(
                                                location.default_radius,
                                            )
                                        }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex justify-end gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                @click="startEditing(location)"
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="destructive"
                                                :disabled="
                                                    deleteRequest.processing
                                                "
                                                @click="
                                                    deleteLocation(location)
                                                "
                                            >
                                                Delete
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
