<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import { ChevronDown } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormValidationBanner from '@/Components/FormValidationBanner.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useToast } from '@/Composables/useToast';
import { openTripOnGoogleMaps, tripHasMapLink } from '@/Composables/tripMapUrl';

type VehicleOption = {
    id: number;
    name: string;
    registration_number: string | null;
    starting_odometer_km: number | null;
    is_active: boolean;
};

const props = defineProps<{
    isEditing: boolean;
    trip: null | {
        id: number;
        vehicle_id: number;
        trip_date: string | null;
        started_at: string | null;
        ended_at: string | null;
        duration_seconds: number | null;
        distance_km: number;
        purpose: 'business' | 'private';
        estimated_opening_km: number | null;
        estimated_closing_km: number | null;
        from_location: string | null;
        to_location: string | null;
        start_latitude: number | null;
        start_longitude: number | null;
        end_latitude: number | null;
        end_longitude: number | null;
        notes: string | null;
    };
    vehicles: VehicleOption[];
    prefill?: {
        vehicle_id?: number | null;
    } | null;
}>();

const toast = useToast();
const saving = ref(false);
const { fieldErrors, setFromZod, setFromServer, clear, clearField, messages: clientErrorMessages } = useFieldErrors();

const hasExistingCoordinates = Boolean(
    props.trip?.start_latitude != null ||
        props.trip?.start_longitude != null ||
        props.trip?.end_latitude != null ||
        props.trip?.end_longitude != null,
);
const showCoordinates = ref(hasExistingCoordinates);

const emptyToNull = (value: unknown) =>
    value === '' || value === null || value === undefined || (typeof value === 'number' && Number.isNaN(value))
        ? null
        : value;

const optionalCoord = (message: string, min: number, max: number) =>
    z.preprocess(
        emptyToNull,
        z.number({ invalid_type_error: message }).min(min, message).max(max, message).nullable(),
    );

const defaultVehicleId =
    props.trip?.vehicle_id ??
    props.prefill?.vehicle_id ??
    props.vehicles.find((vehicle) => vehicle.is_active)?.id ??
    props.vehicles[0]?.id ??
    0;

const { values, setFieldValue: setVeeFieldValue } = useForm({
    initialValues: {
        vehicle_id: defaultVehicleId,
        trip_date: props.trip?.trip_date ?? new Date().toISOString().slice(0, 10),
        started_at: props.trip?.started_at ?? '',
        ended_at: props.trip?.ended_at ?? '',
        distance_km: props.trip?.distance_km ?? ('' as string | number),
        purpose: props.trip?.purpose ?? ('business' as 'business' | 'private'),
        from_location: props.trip?.from_location ?? '',
        to_location: props.trip?.to_location ?? '',
        start_latitude: props.trip?.start_latitude ?? null,
        start_longitude: props.trip?.start_longitude ?? null,
        end_latitude: props.trip?.end_latitude ?? null,
        end_longitude: props.trip?.end_longitude ?? null,
        notes: props.trip?.notes ?? '',
    },
});

const setFieldValue = (path: string, value: unknown) => {
    setVeeFieldValue(path, value);
    clearField(path);
};

const formValues = computed<Record<string, any>>(() => ((values as any)?.value ?? values) as Record<string, any>);

const mapTripFields = computed(() => ({
    start_latitude: formValues.value.start_latitude ?? null,
    start_longitude: formValues.value.start_longitude ?? null,
    end_latitude: formValues.value.end_latitude ?? null,
    end_longitude: formValues.value.end_longitude ?? null,
    from_location: formValues.value.from_location ?? null,
    to_location: formValues.value.to_location ?? null,
}));

const canOpenMap = computed(() => tripHasMapLink(mapTripFields.value));

const openMap = () => {
    if (!openTripOnGoogleMaps(mapTripFields.value)) {
        toast.error('Add a route or GPS coordinates to open this trip on Google Maps.');
    }
};

const computedDurationLabel = computed(() => {
    const start = formValues.value.started_at;
    const end = formValues.value.ended_at;
    if (!start || !end) return null;
    const startMs = Date.parse(start);
    const endMs = Date.parse(end);
    if (Number.isNaN(startMs) || Number.isNaN(endMs) || endMs < startMs) return null;

    const totalSeconds = Math.round((endMs - startMs) / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    const seconds = totalSeconds % 60;
    return minutes > 0 ? `${minutes}m ${seconds}s` : `${seconds}s`;
});

const computedDurationMinutes = computed(() => {
    const start = formValues.value.started_at;
    const end = formValues.value.ended_at;
    if (!start || !end) return null;
    const startMs = Date.parse(start);
    const endMs = Date.parse(end);
    if (Number.isNaN(startMs) || Number.isNaN(endMs) || endMs < startMs) return null;
    return Math.round(((endMs - startMs) / 60000) * 100) / 100;
});

watch(
    () => formValues.value.started_at,
    (startedAt) => {
        if (startedAt && typeof startedAt === 'string' && startedAt.length >= 10) {
            setVeeFieldValue('trip_date', startedAt.slice(0, 10));
        }
    },
);

const schema = z
    .object({
        vehicle_id: z.coerce.number().int().positive('Select a vehicle'),
        trip_date: z.string().optional(),
        started_at: z.string().optional(),
        ended_at: z.string().optional(),
        distance_km: z.coerce.number().min(0, 'Distance cannot be negative'),
        purpose: z.enum(['business', 'private']),
        from_location: z.string().optional(),
        to_location: z.string().optional(),
        start_latitude: optionalCoord('Invalid latitude', -90, 90),
        start_longitude: optionalCoord('Invalid longitude', -180, 180),
        end_latitude: optionalCoord('Invalid latitude', -90, 90),
        end_longitude: optionalCoord('Invalid longitude', -180, 180),
        notes: z.string().optional(),
    })
    .superRefine((data, ctx) => {
        if (!data.trip_date && !data.started_at) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                path: ['trip_date'],
                message: 'Provide a trip date or start time',
            });
        }
        if (data.started_at && data.ended_at && Date.parse(data.ended_at) < Date.parse(data.started_at)) {
            ctx.addIssue({
                code: z.ZodIssueCode.custom,
                path: ['ended_at'],
                message: 'End time must be after start time',
            });
        }
    });

const submit = () => {
    if (saving.value) return;

    const parsed = schema.safeParse({
        ...formValues.value,
        start_latitude: emptyToNull(formValues.value.start_latitude),
        start_longitude: emptyToNull(formValues.value.start_longitude),
        end_latitude: emptyToNull(formValues.value.end_latitude),
        end_longitude: emptyToNull(formValues.value.end_longitude),
    });

    if (!parsed.success) {
        setFromZod(parsed.error);
        if (
            parsed.error.issues.some((issue) =>
                ['start_latitude', 'start_longitude', 'end_latitude', 'end_longitude'].includes(String(issue.path[0])),
            )
        ) {
            showCoordinates.value = true;
        }
        return;
    }

    clear();

    const durationMinutes = computedDurationMinutes.value;
    const payload = {
        vehicle_id: parsed.data.vehicle_id,
        trip_date: parsed.data.trip_date || parsed.data.started_at?.slice(0, 10) || null,
        started_at: parsed.data.started_at || null,
        ended_at: parsed.data.ended_at || null,
        duration_seconds: durationMinutes == null ? null : Math.round(durationMinutes * 60),
        distance_km: parsed.data.distance_km,
        purpose: parsed.data.purpose,
        from_location: parsed.data.from_location?.trim() || null,
        to_location: parsed.data.to_location?.trim() || null,
        start_latitude: parsed.data.start_latitude,
        start_longitude: parsed.data.start_longitude,
        end_latitude: parsed.data.end_latitude,
        end_longitude: parsed.data.end_longitude,
        notes: parsed.data.notes?.trim() || null,
    };

    const visitOptions = {
        onStart: () => {
            saving.value = true;
        },
        onSuccess: () => {
            toast.success(props.isEditing ? 'Trip updated.' : 'Trip logged.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
            if (
                Object.keys(errors).some((key) =>
                    ['start_latitude', 'start_longitude', 'end_latitude', 'end_longitude'].includes(key),
                )
            ) {
                showCoordinates.value = true;
            }
            if (!Object.keys(errors).length) {
                toast.error('Could not save this trip.');
            }
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (props.isEditing && props.trip) {
        router.put(route('vehicles.trips.update', props.trip.id), payload, visitOptions);
        return;
    }

    router.post(route('vehicles.trips.store'), payload, visitOptions);
};

const formatKm = (km: number | null | undefined) =>
    km == null ? '—' : `${Number(km).toLocaleString(undefined, { maximumFractionDigits: 1 })} km`;

const vehicleOptions = computed(() =>
    props.vehicles.map((vehicle) => ({
        label: vehicle.registration_number
            ? `${vehicle.name} (${vehicle.registration_number})`
            : vehicle.name,
        value: String(vehicle.id),
    })),
);
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit trip' : 'Log trip'"
        :breadcrumbs="[
            { label: 'Travel', href: route('vehicles.trips.index') },
            { label: 'Log book', href: route('vehicles.trips.index') },
            { label: isEditing ? 'Edit' : 'Log trip' },
        ]"
    >
        <PageHeader
            :title="isEditing ? 'Edit trip' : 'Log trip'"
            subtitle="Capture the essentials for your travel log book"
        >
            <template v-if="canOpenMap" #actions>
                <AppButton type="button" variant="secondary" @click="openMap">
                    View on map
                </AppButton>
            </template>
        </PageHeader>

        <FormValidationBanner class="mt-5" :errors="clientErrorMessages" />

        <form class="mt-5 space-y-5" @submit.prevent="submit">
            <AppCard>
                <section>
                    <h3 class="text-sm font-semibold text-slate-900">Trip</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Vehicle, purpose, and distance</p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2 lg:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                Vehicle <span class="text-rose-600">*</span>
                            </label>
                            <AppSelect
                                :model-value="String(formValues.vehicle_id || '')"
                                :options="vehicleOptions"
                                @update:model-value="setFieldValue('vehicle_id', Number($event))"
                            />
                            <p v-if="fieldErrors.vehicle_id" class="mt-1 text-xs text-rose-600">
                                {{ fieldErrors.vehicle_id }}
                            </p>
                            <p v-if="!vehicles.length" class="mt-1 text-xs text-amber-700">
                                Add a vehicle first before logging trips.
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                Purpose <span class="text-rose-600">*</span>
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <AppButton
                                    type="button"
                                    size="sm"
                                    :variant="formValues.purpose === 'business' ? 'primary' : 'secondary'"
                                    class="w-full"
                                    @click="setFieldValue('purpose', 'business')"
                                >
                                    Business
                                </AppButton>
                                <AppButton
                                    type="button"
                                    size="sm"
                                    :variant="formValues.purpose === 'private' ? 'primary' : 'secondary'"
                                    class="w-full"
                                    @click="setFieldValue('purpose', 'private')"
                                >
                                    Private
                                </AppButton>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                Date <span class="text-rose-600">*</span>
                            </label>
                            <AppInput
                                :model-value="formValues.trip_date"
                                type="date"
                                required
                                @update:model-value="setFieldValue('trip_date', $event)"
                            />
                            <p v-if="fieldErrors.trip_date" class="mt-1 text-xs text-rose-600">
                                {{ fieldErrors.trip_date }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                Distance (km) <span class="text-rose-600">*</span>
                            </label>
                            <AppInput
                                :model-value="formValues.distance_km === '' ? '' : String(formValues.distance_km ?? '')"
                                type="number"
                                step="0.1"
                                min="0"
                                inputmode="decimal"
                                class="tabular-nums"
                                required
                                @update:model-value="setFieldValue('distance_km', $event === '' ? '' : Number($event))"
                            />
                            <p v-if="fieldErrors.distance_km" class="mt-1 text-xs text-rose-600">
                                {{ fieldErrors.distance_km }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="isEditing"
                        class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm"
                    >
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Opening (est.)</p>
                            <p class="mt-0.5 font-medium tabular-nums text-slate-900">
                                {{ formatKm(trip?.estimated_opening_km) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Closing (est.)</p>
                            <p class="mt-0.5 font-medium tabular-nums text-slate-900">
                                {{ formatKm(trip?.estimated_closing_km) }}
                            </p>
                        </div>
                        <p class="text-xs text-slate-500 sm:ml-auto">
                            From starting odometer and logged distances
                        </p>
                    </div>
                </section>
            </AppCard>

            <AppCard>
                <section>
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">When</h3>
                            <p class="mt-0.5 text-xs text-slate-500">Optional start and end times</p>
                        </div>
                        <p v-if="computedDurationLabel" class="text-sm tabular-nums text-slate-600">
                            Duration {{ computedDurationLabel }}
                        </p>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Started at</label>
                            <AppInput
                                :model-value="formValues.started_at"
                                type="datetime-local"
                                @update:model-value="setFieldValue('started_at', $event)"
                            />
                            <p v-if="fieldErrors.started_at" class="mt-1 text-xs text-rose-600">
                                {{ fieldErrors.started_at }}
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Ended at</label>
                            <AppInput
                                :model-value="formValues.ended_at"
                                type="datetime-local"
                                @update:model-value="setFieldValue('ended_at', $event)"
                            />
                            <p v-if="fieldErrors.ended_at" class="mt-1 text-xs text-rose-600">
                                {{ fieldErrors.ended_at }}
                            </p>
                        </div>
                    </div>
                </section>
            </AppCard>

            <AppCard>
                <section>
                    <h3 class="text-sm font-semibold text-slate-900">Route</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Where the trip started and ended</p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                            <AppInput
                                :model-value="formValues.from_location"
                                placeholder="Start address or place"
                                @update:model-value="setFieldValue('from_location', $event)"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                            <AppInput
                                :model-value="formValues.to_location"
                                placeholder="End address or place"
                                @update:model-value="setFieldValue('to_location', $event)"
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                            <textarea
                                :value="formValues.notes"
                                rows="3"
                                placeholder="Optional context for this trip"
                                class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25"
                                @input="setFieldValue('notes', ($event.target as HTMLTextAreaElement).value)"
                            />
                        </div>
                    </div>

                    <div class="mt-6 border-t border-slate-100 pt-4">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 text-left"
                            @click="showCoordinates = !showCoordinates"
                        >
                            <div>
                                <p class="text-sm font-medium text-slate-900">GPS coordinates</p>
                                <p class="text-xs text-slate-500">Optional — useful for telematics imports</p>
                            </div>
                            <ChevronDown
                                class="h-4 w-4 shrink-0 text-slate-500 transition"
                                :class="showCoordinates ? 'rotate-180' : ''"
                            />
                        </button>

                        <div v-if="showCoordinates" class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Start latitude</label>
                                <AppInput
                                    :model-value="
                                        formValues.start_latitude == null ? '' : String(formValues.start_latitude)
                                    "
                                    type="number"
                                    step="any"
                                    inputmode="decimal"
                                    class="tabular-nums"
                                    @update:model-value="
                                        setFieldValue('start_latitude', $event === '' ? null : Number($event))
                                    "
                                />
                                <p v-if="fieldErrors.start_latitude" class="mt-1 text-xs text-rose-600">
                                    {{ fieldErrors.start_latitude }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Start longitude</label>
                                <AppInput
                                    :model-value="
                                        formValues.start_longitude == null ? '' : String(formValues.start_longitude)
                                    "
                                    type="number"
                                    step="any"
                                    inputmode="decimal"
                                    class="tabular-nums"
                                    @update:model-value="
                                        setFieldValue('start_longitude', $event === '' ? null : Number($event))
                                    "
                                />
                                <p v-if="fieldErrors.start_longitude" class="mt-1 text-xs text-rose-600">
                                    {{ fieldErrors.start_longitude }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">End latitude</label>
                                <AppInput
                                    :model-value="
                                        formValues.end_latitude == null ? '' : String(formValues.end_latitude)
                                    "
                                    type="number"
                                    step="any"
                                    inputmode="decimal"
                                    class="tabular-nums"
                                    @update:model-value="
                                        setFieldValue('end_latitude', $event === '' ? null : Number($event))
                                    "
                                />
                                <p v-if="fieldErrors.end_latitude" class="mt-1 text-xs text-rose-600">
                                    {{ fieldErrors.end_latitude }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">End longitude</label>
                                <AppInput
                                    :model-value="
                                        formValues.end_longitude == null ? '' : String(formValues.end_longitude)
                                    "
                                    type="number"
                                    step="any"
                                    inputmode="decimal"
                                    class="tabular-nums"
                                    @update:model-value="
                                        setFieldValue('end_longitude', $event === '' ? null : Number($event))
                                    "
                                />
                                <p v-if="fieldErrors.end_longitude" class="mt-1 text-xs text-rose-600">
                                    {{ fieldErrors.end_longitude }}
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            </AppCard>

            <FormActions>
                <AppButton type="submit" variant="primary" :loading="saving" :disabled="saving || !vehicles.length">
                    {{ saving ? 'Saving…' : isEditing ? 'Update trip' : 'Log trip' }}
                </AppButton>
                <AppButton type="button" variant="secondary" @click="router.visit(route('vehicles.trips.index'))">
                    Cancel
                </AppButton>
            </FormActions>
        </form>
    </AppLayout>
</template>
