<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useTravelTabs } from '@/Composables/useFeatureTabs';
import { openTripOnGoogleMaps, tripHasMapLink } from '@/Composables/tripMapUrl';
import { useToast } from '@/Composables/useToast';
import { formatTripDate } from '@/Composables/formatTripDate';

const travelTabs = useTravelTabs();
const page = usePage();
const toast = useToast();
const aiEnabled = computed(() => Boolean(page.props.ai_enabled));
const canManage = computed(() => {
    const perms = page.props.team_permissions;
    return Array.isArray(perms) && perms.includes('vehicles.manage');
});

type VehicleOption = {
    id: number;
    name: string;
    registration_number: string | null;
};

type TripRow = {
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
    start_latitude: number | null;
    start_longitude: number | null;
    end_latitude: number | null;
    end_longitude: number | null;
    notes: string | null;
    vehicle: {
        id: number;
        name: string;
        registration_number: string | null;
    } | null;
};

const props = defineProps<{
    trips: {
        data: TripRow[];
        current_page: number;
        last_page: number;
    };
    vehicles: VehicleOption[];
    filters: {
        search: string | null;
        purpose: string;
        vehicle_id: number | null;
        from: string | null;
        to: string | null;
    };
}>();

const filters = ref({
    search: props.filters.search ?? '',
    purpose: props.filters.purpose ?? 'all',
    vehicle_id: props.filters.vehicle_id ? String(props.filters.vehicle_id) : 'all',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const formatKm = (km: number | null | undefined) =>
    km == null ? '—' : `${Number(km).toLocaleString(undefined, { maximumFractionDigits: 1 })} km`;

const routeLabel = (trip: TripRow) => {
    if (!trip.from_location && !trip.to_location) return null;
    return `${trip.from_location || '—'} → ${trip.to_location || '—'}`;
};

const isBusiness = (trip: TripRow) => trip.purpose === 'business';

const tripCardClass = (trip: TripRow) =>
    isBusiness(trip)
        ? 'border-sky-300 bg-sky-100/80 active:bg-sky-100'
        : 'border-slate-200 bg-white active:bg-slate-50';

const tripRowClass = (trip: TripRow) =>
    isBusiness(trip)
        ? 'border-l-2 border-l-sky-400 bg-sky-100/60 hover:bg-sky-100'
        : 'hover:bg-slate-50';

const openTrip = (trip: TripRow) => {
    router.visit(route('vehicles.trips.edit', trip.id));
};

const filterQuery = () => ({
    search: filters.value.search || undefined,
    purpose: filters.value.purpose === 'all' ? undefined : filters.value.purpose,
    vehicle_id: filters.value.vehicle_id === 'all' ? undefined : filters.value.vehicle_id,
    from: filters.value.from || undefined,
    to: filters.value.to || undefined,
});

const applyFilters = (page = 1) => {
    router.get(route('vehicles.trips.index'), { ...filterQuery(), page }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const clearFilters = () => {
    filters.value = { search: '', purpose: 'all', vehicle_id: 'all', from: '', to: '' };
    applyFilters();
};

const exportCsv = () => {
    window.location.href = route('vehicles.trips.export', filterQuery());
};

const confirmDelete = (trip: TripRow) => {
    if (!confirm(`Delete trip from ${trip.trip_date ?? 'this date'}?`)) return;
    router.delete(route('vehicles.trips.destroy', trip.id), { preserveScroll: true });
};

const togglingPurposeIds = ref(new Set<number>());

const togglePurpose = (trip: TripRow) => {
    if (!canManage.value || togglingPurposeIds.value.has(trip.id)) return;
    const next = new Set(togglingPurposeIds.value);
    next.add(trip.id);
    togglingPurposeIds.value = next;

    router.post(route('vehicles.trips.toggle-purpose', trip.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            const cleared = new Set(togglingPurposeIds.value);
            cleared.delete(trip.id);
            togglingPurposeIds.value = cleared;
        },
    });
};

const rowActionItems = (trip: TripRow) => [
    ...(tripHasMapLink(trip) ? [{ id: 'map', label: 'View on map' }] : []),
    { id: 'edit', label: 'Edit' },
    { id: 'delete', label: 'Delete' },
];

const onRowAction = (trip: TripRow, actionId: string) => {
    if (actionId === 'map') {
        if (!openTripOnGoogleMaps(trip)) {
            toast.error('This trip has no location to show on a map.');
        }
        return;
    }
    if (actionId === 'edit') {
        openTrip(trip);
        return;
    }
    if (actionId === 'delete') {
        confirmDelete(trip);
    }
};
</script>

<template>
    <FeatureShell
        title="Travel"
        section="trips"
        :tabs="travelTabs"
        document-title="Trip log"
        subtitle="Business and private travel log book"
    >
        <template #actions>
            <AppButton variant="secondary" @click="exportCsv">Export CSV</AppButton>
            <AppButton
                v-if="aiEnabled && canManage"
                variant="secondary"
                @click="router.visit(route('vehicles.trips.import.create'))"
            >
                Smart AI import
            </AppButton>
            <AppButton variant="primary" @click="router.visit(route('vehicles.trips.create'))">
                Log trip
            </AppButton>
        </template>

        <AppCard>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <div class="xl:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Search</label>
                    <AppInput
                        v-model="filters.search"
                        placeholder="Route or notes…"
                        @keydown.enter="applyFilters()"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Purpose</label>
                    <AppSelect
                        :model-value="filters.purpose"
                        :options="[
                            { label: 'All', value: 'all' },
                            { label: 'Business', value: 'business' },
                            { label: 'Private', value: 'private' },
                        ]"
                        @update:model-value="filters.purpose = $event"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Vehicle</label>
                    <AppSelect
                        :model-value="filters.vehicle_id"
                        :options="[
                            { label: 'All vehicles', value: 'all' },
                            ...vehicles.map((vehicle) => ({
                                label: vehicle.registration_number
                                    ? `${vehicle.name} (${vehicle.registration_number})`
                                    : vehicle.name,
                                value: String(vehicle.id),
                            })),
                        ]"
                        @update:model-value="filters.vehicle_id = $event"
                    />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                        <AppInput v-model="filters.from" type="date" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                        <AppInput v-model="filters.to" type="date" />
                    </div>
                </div>
            </div>
            <div class="mt-3 flex gap-2">
                <AppButton variant="secondary" @click="applyFilters()">Apply</AppButton>
                <AppButton variant="ghost" @click="clearFilters">Clear</AppButton>
            </div>
            <p class="mt-3 text-xs text-slate-500">
                Opening/closing odometer are estimated from each vehicle’s starting (purchase) reading and trip
                distances. Use date filters, then Export CSV for a tax-period log book.
            </p>
        </AppCard>

        <AppCard class="mt-5">
            <!-- Narrow / medium screens: stacked cards (wide table squeezes the route column) -->
            <div class="space-y-3 lg:hidden">
                <div
                    v-for="trip in trips.data"
                    :key="`mobile-${trip.id}`"
                    role="button"
                    tabindex="0"
                    class="w-full cursor-pointer rounded-xl border p-4 text-left shadow-sm"
                    :class="tripCardClass(trip)"
                    @click="openTrip(trip)"
                    @keydown.enter.prevent="openTrip(trip)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-slate-900">{{ formatTripDate(trip.trip_date) }}</p>
                            <p class="truncate text-sm text-slate-600">{{ trip.vehicle?.name || '—' }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5" @click.stop>
                            <button
                                v-if="canManage"
                                type="button"
                                class="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 active:scale-[0.98]"
                                :disabled="togglingPurposeIds.has(trip.id)"
                                :title="trip.purpose === 'business' ? 'Switch to private' : 'Switch to business'"
                                :aria-label="trip.purpose === 'business' ? 'Switch to private' : 'Switch to business'"
                                @click="togglePurpose(trip)"
                            >
                                <AppBadge :variant="trip.purpose === 'business' ? 'info' : 'neutral'">
                                    {{ trip.purpose }}
                                </AppBadge>
                            </button>
                            <AppBadge v-else :variant="trip.purpose === 'business' ? 'info' : 'neutral'">
                                {{ trip.purpose }}
                            </AppBadge>
                            <InvoiceRowActionsMenu
                                :actions="rowActionItems(trip)"
                                :aria-label="`Actions for trip ${trip.id}`"
                                @select="(id) => onRowAction(trip, id)"
                            />
                        </div>
                    </div>
                    <p
                        class="mt-3 break-words text-sm text-slate-600"
                        :class="{ 'text-slate-400': !routeLabel(trip) }"
                    >
                        {{ routeLabel(trip) || '—' }}
                    </p>
                    <div class="mt-3 flex items-end justify-between gap-3 border-t border-slate-100 pt-3 text-sm">
                        <div class="min-w-0 text-xs text-slate-500">
                            <p>
                                <span class="tabular-nums">{{ formatKm(trip.estimated_opening_km) }}</span>
                                →
                                <span class="tabular-nums">{{ formatKm(trip.estimated_closing_km) }}</span>
                            </p>
                            <p class="mt-0.5">Opening → closing (est.)</p>
                        </div>
                        <p class="shrink-0 font-semibold tabular-nums text-slate-900">
                            {{ formatKm(trip.distance_km) }}
                        </p>
                    </div>
                </div>
                <EmptyState
                    v-if="!trips.data.length"
                    title="No trips yet"
                    description="Log business and private trips to build your travel log book."
                />
                <div
                    v-if="trips.last_page > 1"
                    class="flex items-center justify-between border-t border-slate-200 pt-3 text-xs text-slate-500"
                >
                    <p>Page {{ trips.current_page }} of {{ trips.last_page }}</p>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded border border-slate-200 px-2 py-1 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="trips.current_page <= 1"
                            @click="applyFilters(trips.current_page - 1)"
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            class="rounded border border-slate-200 px-2 py-1 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="trips.current_page >= trips.last_page"
                            @click="applyFilters(trips.current_page + 1)"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>

            <div class="hidden lg:block">
                <AppTable
                    embedded
                    table-class="min-w-[1120px] text-sm"
                    :columns="[
                        { key: 'date', label: 'Date', widthClass: 'whitespace-nowrap' },
                        { key: 'vehicle', label: 'Vehicle', widthClass: 'whitespace-nowrap' },
                        { key: 'route', label: 'Route', widthClass: 'min-w-[18rem]' },
                        { key: 'purpose', label: 'Purpose', widthClass: 'whitespace-nowrap' },
                        { key: 'opening', label: 'Opening (est.)', widthClass: 'whitespace-nowrap' },
                        { key: 'closing', label: 'Closing (est.)', widthClass: 'whitespace-nowrap' },
                        { key: 'distance', label: 'Distance', widthClass: 'whitespace-nowrap' },
                        { key: 'actions', label: '', widthClass: 'w-[1%] whitespace-nowrap text-right' },
                    ]"
                    :page="trips.current_page"
                    :last-page="trips.last_page"
                    @page-change="applyFilters"
                >
                    <tr
                        v-for="trip in trips.data"
                        :key="trip.id"
                        class="cursor-pointer"
                        :class="tripRowClass(trip)"
                        @click="openTrip(trip)"
                    >
                        <td class="whitespace-nowrap px-3 py-2">{{ formatTripDate(trip.trip_date) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 font-medium text-slate-900">
                            {{ trip.vehicle?.name || '—' }}
                        </td>
                        <td class="min-w-[18rem] max-w-[28rem] px-3 py-2 text-slate-600">
                            <span v-if="routeLabel(trip)" class="break-words">{{ routeLabel(trip) }}</span>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2" @click.stop>
                            <button
                                v-if="canManage"
                                type="button"
                                class="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 active:scale-[0.98]"
                                :disabled="togglingPurposeIds.has(trip.id)"
                                :title="trip.purpose === 'business' ? 'Switch to private' : 'Switch to business'"
                                :aria-label="trip.purpose === 'business' ? 'Switch to private' : 'Switch to business'"
                                @click="togglePurpose(trip)"
                            >
                                <AppBadge :variant="trip.purpose === 'business' ? 'info' : 'neutral'">
                                    {{ trip.purpose }}
                                </AppBadge>
                            </button>
                            <AppBadge v-else :variant="trip.purpose === 'business' ? 'info' : 'neutral'">
                                {{ trip.purpose }}
                            </AppBadge>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ formatKm(trip.estimated_opening_km) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ formatKm(trip.estimated_closing_km) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ formatKm(trip.distance_km) }}</td>
                        <td class="px-3 py-2" @click.stop>
                            <div class="flex justify-end">
                                <InvoiceRowActionsMenu
                                    :actions="rowActionItems(trip)"
                                    :aria-label="`Actions for trip ${trip.id}`"
                                    @select="(id) => onRowAction(trip, id)"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!trips.data.length">
                        <td colspan="8" class="px-4 py-6">
                            <EmptyState
                                title="No trips yet"
                                description="Log business and private trips to build your travel log book."
                            />
                        </td>
                    </tr>
                </AppTable>
            </div>
        </AppCard>
    </FeatureShell>
</template>
