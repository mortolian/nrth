<script setup lang="ts">
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';

type TripHistoryRow = {
    id: number;
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
    notes: string | null;
};

const props = defineProps<{
    vehicle: {
        id: number;
        name: string;
        make: string | null;
        model: string | null;
        year: number | null;
        registration_number: string | null;
        vin: string | null;
        current_odometer_km: number | null;
        notes: string | null;
        is_active: boolean;
    };
    trip_history: {
        data: TripHistoryRow[];
        current_page: number;
        last_page: number;
    };
    stats: {
        business_km_ytd: number;
        private_km_ytd: number;
        total_km_ytd: number;
        trip_count: number;
        trip_count_ytd: number;
    };
}>();

const formatKm = (km: number | null) =>
    km == null ? '—' : `${Number(km).toLocaleString(undefined, { maximumFractionDigits: 1 })} km`;

const vehicleSubtitle = computed(() =>
    [props.vehicle.make, props.vehicle.model, props.vehicle.year].filter(Boolean).join(' '),
);

const canDelete = computed(() => props.stats.trip_count === 0);

const goHistoryPage = (page: number) => {
    router.get(route('vehicles.show', props.vehicle.id), { page }, { preserveState: true, preserveScroll: true });
};

const deleteVehicle = () => {
    if (!canDelete.value || !confirm(`Delete vehicle “${props.vehicle.name}”? This cannot be undone.`)) return;
    router.delete(route('vehicles.destroy', props.vehicle.id));
};

const confirmDeleteTrip = (trip: TripHistoryRow) => {
    if (!confirm(`Delete trip from ${trip.trip_date ?? 'this date'}?`)) return;
    router.delete(route('vehicles.trips.destroy', trip.id), { preserveScroll: true });
};

const rowActionItems = () => [
    { id: 'edit', label: 'Edit' },
    { id: 'delete', label: 'Delete' },
];

const onRowAction = (trip: TripHistoryRow, actionId: string) => {
    if (actionId === 'edit') {
        router.visit(route('vehicles.trips.edit', trip.id));
        return;
    }
    if (actionId === 'delete') {
        confirmDeleteTrip(trip);
    }
};
</script>

<template>
    <AppLayout
        :title="vehicle.name"
        :breadcrumbs="[
            { label: 'Travel', href: route('vehicles.trips.index') },
            { label: 'Vehicles', href: route('vehicles.index') },
            { label: vehicle.name },
        ]"
    >
        <PageHeader :title="vehicle.name" :subtitle="vehicleSubtitle || 'Vehicle profile and trip history'">
            <template #actions>
                <AppButton
                    variant="secondary"
                    @click="router.visit(route('vehicles.trips.create', { vehicle_id: vehicle.id }))"
                >
                    Log trip
                </AppButton>
                <AppButton variant="primary" @click="router.visit(route('vehicles.edit', vehicle.id))">
                    Edit vehicle
                </AppButton>
                <AppButton
                    v-if="canDelete"
                    variant="ghost"
                    class="text-rose-600 hover:bg-rose-50"
                    @click="deleteVehicle"
                >
                    Delete
                </AppButton>
            </template>
        </PageHeader>

        <div class="mt-5 grid gap-4 lg:grid-cols-3">
            <AppCard class="lg:col-span-2">
                <h3 class="text-sm font-semibold text-slate-900">Details</h3>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Registration</dt>
                        <dd class="font-medium text-slate-900">{{ vehicle.registration_number || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">VIN</dt>
                        <dd class="font-medium text-slate-900">{{ vehicle.vin || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Odometer</dt>
                        <dd class="font-medium tabular-nums text-slate-900">
                            {{ formatKm(vehicle.current_odometer_km) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Make / model</dt>
                        <dd class="font-medium text-slate-900">{{ vehicleSubtitle || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Status</dt>
                        <dd>
                            <AppBadge :variant="vehicle.is_active ? 'success' : 'neutral'">
                                {{ vehicle.is_active ? 'active' : 'inactive' }}
                            </AppBadge>
                        </dd>
                    </div>
                </dl>
                <div v-if="vehicle.notes" class="mt-4">
                    <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Notes</h4>
                    <p class="mt-1 whitespace-pre-wrap text-sm text-slate-800">{{ vehicle.notes }}</p>
                </div>
            </AppCard>

            <AppCard>
                <h3 class="text-sm font-semibold text-slate-900">This year</h3>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-slate-900">
                    {{ formatKm(stats.total_km_ytd) }}
                </p>
                <p class="text-sm text-slate-500">{{ stats.trip_count_ytd }} trips YTD · {{ stats.trip_count }} all time</p>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Business</dt>
                        <dd class="font-medium tabular-nums text-slate-900">{{ formatKm(stats.business_km_ytd) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Private</dt>
                        <dd class="font-medium tabular-nums text-slate-900">{{ formatKm(stats.private_km_ytd) }}</dd>
                    </div>
                </dl>
            </AppCard>
        </div>

        <AppCard class="mt-5">
            <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-lg font-semibold text-slate-900">Trip history</h3>
                <Link
                    :href="route('vehicles.trips.index', { vehicle_id: vehicle.id })"
                    class="text-sm font-medium text-brand-600 hover:underline"
                >
                    View in log book
                </Link>
            </div>
            <AppTable
                table-class="text-sm"
                :columns="[
                    { key: 'date', label: 'Date' },
                    { key: 'route', label: 'Route' },
                    { key: 'purpose', label: 'Purpose' },
                    { key: 'opening', label: 'Opening (est.)' },
                    { key: 'closing', label: 'Closing (est.)' },
                    { key: 'distance', label: 'Distance' },
                    { key: 'actions', label: '' },
                ]"
                :page="trip_history.current_page"
                :last-page="trip_history.last_page"
                @page-change="goHistoryPage"
            >
                <tr
                    v-for="row in trip_history.data"
                    :key="row.id"
                    class="cursor-pointer hover:bg-slate-50"
                    @click="router.visit(route('vehicles.trips.edit', row.id))"
                >
                    <td class="whitespace-nowrap px-3 py-2">{{ row.trip_date || '—' }}</td>
                    <td class="px-3 py-2 text-slate-600">
                        <span v-if="row.from_location || row.to_location">
                            {{ row.from_location || '—' }} → {{ row.to_location || '—' }}
                        </span>
                        <span v-else class="text-slate-400">—</span>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2">
                        <AppBadge :variant="row.purpose === 'business' ? 'info' : 'neutral'">
                            {{ row.purpose }}
                        </AppBadge>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ formatKm(row.estimated_opening_km) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ formatKm(row.estimated_closing_km) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ formatKm(row.distance_km) }}</td>
                    <td class="px-3 py-2" @click.stop>
                        <div class="flex justify-end">
                            <InvoiceRowActionsMenu
                                :actions="rowActionItems()"
                                :aria-label="`Actions for trip ${row.id}`"
                                @select="(id) => onRowAction(row, id)"
                            />
                        </div>
                    </td>
                </tr>
                <tr v-if="!trip_history.data.length">
                    <td colspan="7" class="px-4 py-6">
                        <EmptyState
                            title="No trips yet"
                            description="Trips logged against this vehicle will show up here."
                        />
                    </td>
                </tr>
            </AppTable>
        </AppCard>
    </AppLayout>
</template>
