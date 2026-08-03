<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import { useTravelTabs } from '@/Composables/useFeatureTabs';

const travelTabs = useTravelTabs();

type VehicleOption = {
    id: number;
    name: string;
    registration_number: string | null;
};

type DraftTrip = {
    key: string;
    include: boolean;
    is_duplicate: boolean;
    duplicate_reason: string | null;
    segments_merged: number;
    trip_date: string | null;
    started_at: string | null;
    ended_at: string | null;
    duration_seconds: number | null;
    distance_km: number;
    purpose: 'business' | 'private';
    from_location: string | null;
    to_location: string | null;
    notes: string | null;
};

type Draft = {
    vehicle_id: number;
    filename: string;
    truncated: boolean;
    parser: string;
    source_segments_count: number;
    summary: {
        total: number;
        new: number;
        duplicates: number;
        segments_merged_away: number;
    };
    trips: DraftTrip[];
};

const props = defineProps<{
    draft: Draft;
    vehicles: VehicleOption[];
}>();

const selected = ref(
    new Set(
        props.draft.trips
            .filter((trip) => trip.include && !trip.is_duplicate)
            .map((trip) => trip.key),
    ),
);

const form = useForm<{
    vehicle_id: number;
    keys: string[];
}>({
    vehicle_id: props.draft.vehicle_id,
    keys: [],
});

const vehicleSelectOptions = computed(() =>
    props.vehicles.map((vehicle) => ({
        label: vehicle.registration_number
            ? `${vehicle.name} (${vehicle.registration_number})`
            : vehicle.name,
        value: String(vehicle.id),
    })),
);

const selectedCount = computed(() => selected.value.size);

const formatKm = (km: number) =>
    `${Number(km).toLocaleString(undefined, { maximumFractionDigits: 1 })} km`;

const toggle = (key: string, enabled: boolean) => {
    const next = new Set(selected.value);
    if (enabled) {
        next.add(key);
    } else {
        next.delete(key);
    }
    selected.value = next;
};

const selectNewOnly = () => {
    selected.value = new Set(
        props.draft.trips.filter((trip) => !trip.is_duplicate).map((trip) => trip.key),
    );
};

const clearSelection = () => {
    selected.value = new Set();
};

const confirmImport = () => {
    form.keys = Array.from(selected.value);
    form.post(route('vehicles.trips.import.confirm'));
};
</script>

<template>
    <FeatureShell
        title="Travel"
        section="import"
        :tabs="travelTabs"
        document-title="Import preview"
        :subtitle="`Review trips from ${draft.filename}`"
    >
        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <AppCard>
                <p class="text-xs font-medium uppercase text-slate-500">Trips after merge</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ draft.summary.total }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase text-slate-500">New</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-700">{{ draft.summary.new }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase text-slate-500">Duplicates</p>
                <p class="mt-1 text-2xl font-semibold text-amber-700">{{ draft.summary.duplicates }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase text-slate-500">Stops merged away</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ draft.summary.segments_merged_away }}</p>
            </AppCard>
        </div>

        <AppCard class="mt-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-sm flex-1">
                    <label class="mb-1.5 block text-xs font-medium text-slate-500">Import into vehicle</label>
                    <AppSelect
                        :model-value="String(form.vehicle_id)"
                        :options="vehicleSelectOptions"
                        @update:model-value="form.vehicle_id = Number($event)"
                    />
                    <p v-if="form.errors.vehicle_id" class="mt-1.5 text-xs text-red-600">{{ form.errors.vehicle_id }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <AppButton variant="ghost" @click="selectNewOnly">Select new</AppButton>
                    <AppButton variant="ghost" @click="clearSelection">Clear</AppButton>
                </div>
            </div>

            <p v-if="draft.truncated" class="mt-3 text-xs text-amber-700">
                File was truncated to the first rows for scanning. Split large exports if needed.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Source segments: {{ draft.source_segments_count }}
                · Parser: {{ draft.parser === 'telematics' ? 'fleet/GPS columns' : 'AI' }}
            </p>
            <p v-if="form.errors.keys" class="mt-2 text-xs text-red-600">{{ form.errors.keys }}</p>
        </AppCard>

        <AppCard class="mt-5">
            <AppTable
                table-class="text-sm"
                :columns="[
                    { key: 'include', label: '' },
                    { key: 'date', label: 'Date' },
                    { key: 'route', label: 'Route' },
                    { key: 'purpose', label: 'Purpose' },
                    { key: 'distance', label: 'Distance' },
                    { key: 'merged', label: 'Segments' },
                    { key: 'status', label: 'Status' },
                ]"
            >
                <tr
                    v-for="trip in draft.trips"
                    :key="trip.key"
                    :class="trip.is_duplicate ? 'bg-amber-50/60' : 'hover:bg-slate-50'"
                >
                    <td class="px-3 py-2" @click.stop>
                        <input
                            type="checkbox"
                            class="rounded border-slate-300 text-slate-900 focus:ring-slate-400"
                            :checked="selected.has(trip.key)"
                            @change="toggle(trip.key, ($event.target as HTMLInputElement).checked)"
                        >
                    </td>
                    <td class="whitespace-nowrap px-3 py-2">
                        <div>{{ trip.trip_date || '—' }}</div>
                        <div v-if="trip.started_at" class="text-xs text-slate-500">
                            {{ trip.started_at }}
                            <span v-if="trip.ended_at"> → {{ trip.ended_at }}</span>
                        </div>
                    </td>
                    <td class="px-3 py-2 text-slate-600">
                        <span v-if="trip.from_location || trip.to_location">
                            {{ trip.from_location || '—' }} → {{ trip.to_location || '—' }}
                        </span>
                        <span v-else class="text-slate-400">—</span>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2">
                        <AppBadge :variant="trip.purpose === 'business' ? 'info' : 'neutral'">
                            {{ trip.purpose }}
                        </AppBadge>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ formatKm(trip.distance_km) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ trip.segments_merged || 1 }}</td>
                    <td class="px-3 py-2 text-xs">
                        <span v-if="trip.is_duplicate" class="text-amber-800">
                            {{ trip.duplicate_reason || 'Duplicate' }}
                        </span>
                        <span v-else class="text-emerald-700">New</span>
                    </td>
                </tr>
                <tr v-if="!draft.trips.length">
                    <td colspan="7" class="px-4 py-6">
                        <EmptyState title="No trips found" description="Try another export file." />
                    </td>
                </tr>
            </AppTable>

            <FormActions bordered class="mt-4">
                <AppButton
                    variant="primary"
                    :loading="form.processing"
                    :disabled="selectedCount === 0"
                    @click="confirmImport"
                >
                    Import {{ selectedCount }} selected
                </AppButton>
                <AppButton
                    variant="secondary"
                    :disabled="form.processing"
                    @click="router.visit(route('vehicles.trips.import.create'))"
                >
                    Back
                </AppButton>
            </FormActions>
        </AppCard>
    </FeatureShell>
</template>
