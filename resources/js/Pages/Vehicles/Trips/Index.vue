<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
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
const canDelete = computed(() => {
    const perms = page.props.team_permissions;
    return Array.isArray(perms) && perms.includes('vehicles.delete');
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

const appliedFilterQuery = () => ({
    search: props.filters.search || undefined,
    purpose: props.filters.purpose && props.filters.purpose !== 'all'
        ? props.filters.purpose
        : undefined,
    vehicle_id: props.filters.vehicle_id ? String(props.filters.vehicle_id) : undefined,
    from: props.filters.from || undefined,
    to: props.filters.to || undefined,
});

const hasActiveFilters = computed(() => Boolean(
    props.filters.search
    || (props.filters.purpose && props.filters.purpose !== 'all')
    || props.filters.vehicle_id
    || props.filters.from
    || props.filters.to,
));

const pdfTripLimit = 1500;

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
    window.location.href = route('vehicles.trips.export', appliedFilterQuery());
};

const exportPdf = () => {
    if (!props.filters.from || !props.filters.to) {
        toast.error('Choose both a from and to date, then Apply, before exporting a PDF log book.');
        return;
    }
    window.location.href = route('vehicles.trips.export-pdf', appliedFilterQuery());
};

const confirmDelete = (trip: TripRow) => {
    if (!canDelete.value) return;
    if (!confirm(`Delete trip from ${trip.trip_date ?? 'this date'}?`)) return;
    router.delete(route('vehicles.trips.destroy', trip.id), { preserveScroll: true });
};

const selected = ref<number[]>([]);
const pageIds = computed(() => props.trips.data.map((trip) => trip.id));
const allPageSelected = computed(
    () => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)),
);
const somePageSelected = computed(
    () => pageIds.value.some((id) => selected.value.includes(id)) && !allPageSelected.value,
);
const selectAllCheckbox = ref<HTMLInputElement | null>(null);
const selectAllMobileCheckbox = ref<HTMLInputElement | null>(null);

watch([allPageSelected, somePageSelected], async () => {
    await nextTick();
    if (selectAllCheckbox.value) {
        selectAllCheckbox.value.indeterminate = somePageSelected.value;
    }
    if (selectAllMobileCheckbox.value) {
        selectAllMobileCheckbox.value.indeterminate = somePageSelected.value;
    }
}, { immediate: true });

const toggleSelected = (id: number, checked: boolean) => {
    if (checked) {
        if (!selected.value.includes(id)) selected.value.push(id);
        return;
    }
    selected.value = selected.value.filter((item) => item !== id);
};

const toggleSelectAllPage = (checked: boolean) => {
    if (checked) {
        selected.value = [...new Set([...selected.value, ...pageIds.value])];
        return;
    }
    const onPage = new Set(pageIds.value);
    selected.value = selected.value.filter((id) => !onPage.has(id));
};

const confirmBulkDelete = () => {
    if (!canDelete.value || selected.value.length === 0) return;
    const count = selected.value.length;
    if (!confirm(`Delete ${count} selected trip${count === 1 ? '' : 's'}? This cannot be undone.`)) {
        return;
    }
    router.delete(route('vehicles.trips.bulk-destroy'), {
        data: { ids: selected.value },
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
        },
    });
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
    ...(canDelete.value ? [{ id: 'delete', label: 'Delete' }] : []),
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

const tableColumns = computed(() => [
    ...(canDelete.value ? [{ key: 'select', label: '', widthClass: 'w-10' }] : []),
    { key: 'date', label: 'Date', widthClass: 'whitespace-nowrap' },
    { key: 'vehicle', label: 'Vehicle', widthClass: 'whitespace-nowrap' },
    { key: 'route', label: 'Route', widthClass: 'min-w-[18rem]' },
    { key: 'purpose', label: 'Purpose', widthClass: 'whitespace-nowrap' },
    { key: 'distance', label: 'Distance', widthClass: 'whitespace-nowrap' },
    { key: 'actions', label: '', widthClass: 'w-[1%] whitespace-nowrap text-right' },
]);

const emptyColspan = computed(() => tableColumns.value.length);
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
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <AppButton variant="secondary" @click="applyFilters()">Apply</AppButton>
                <AppButton variant="ghost" @click="clearFilters">Clear</AppButton>
                <AppButton variant="secondary" @click="exportCsv">
                    {{ hasActiveFilters ? 'Export filtered CSV' : 'Export CSV' }}
                </AppButton>
                <AppButton variant="secondary" @click="exportPdf">
                    {{ hasActiveFilters ? 'Export filtered PDF' : 'Export PDF' }}
                </AppButton>
            </div>
            <p class="mt-3 text-xs text-slate-500">
                CSV and PDF exports use the filters currently applied to the list (all matching trips, not just this
                page). PDF requires a from/to date range and is capped at
                {{ pdfTripLimit.toLocaleString() }} trips — narrow filters or use CSV for larger extracts.
            </p>
        </AppCard>

        <AppCard class="mt-5">
            <div
                v-if="canDelete && selected.length > 0"
                class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"
            >
                <p class="text-sm text-slate-700">
                    {{ selected.length }} selected
                </p>
                <AppButton variant="danger" size="sm" @click="confirmBulkDelete">
                    Delete selected
                </AppButton>
            </div>

            <!-- Narrow / medium screens: stacked cards (wide table squeezes the route column) -->
            <div class="space-y-3 lg:hidden">
                <div
                    v-if="canDelete && trips.data.length"
                    class="flex items-center gap-2 px-1"
                    @click.stop
                >
                    <input
                        ref="selectAllMobileCheckbox"
                        type="checkbox"
                        class="h-4 w-4 rounded border-slate-300"
                        :checked="allPageSelected"
                        :aria-label="allPageSelected ? 'Deselect all on this page' : 'Select all on this page'"
                        @change="toggleSelectAllPage(($event.target as HTMLInputElement).checked)"
                    >
                    <span class="text-xs text-slate-500">Select page</span>
                </div>
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
                        <div class="flex min-w-0 flex-1 items-start gap-2">
                            <div v-if="canDelete" class="pt-0.5" @click.stop>
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300"
                                    :checked="selected.includes(trip.id)"
                                    :aria-label="`Select trip ${trip.id}`"
                                    @change="toggleSelected(trip.id, ($event.target as HTMLInputElement).checked)"
                                >
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900">{{ formatTripDate(trip.trip_date) }}</p>
                                <p class="truncate text-sm text-slate-600">{{ trip.vehicle?.name || '—' }}</p>
                            </div>
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
                        <p class="min-w-0 text-xs text-slate-500">
                            {{ trip.notes || '—' }}
                        </p>
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
                    :columns="tableColumns"
                    :page="trips.current_page"
                    :last-page="trips.last_page"
                    @page-change="applyFilters"
                >
                    <template v-if="canDelete" #header-select>
                        <input
                            ref="selectAllCheckbox"
                            type="checkbox"
                            class="h-4 w-4 rounded border-slate-300"
                            :checked="allPageSelected"
                            :disabled="trips.data.length === 0"
                            :aria-label="allPageSelected ? 'Deselect all on this page' : 'Select all on this page'"
                            @change="toggleSelectAllPage(($event.target as HTMLInputElement).checked)"
                        >
                    </template>
                    <tr
                        v-for="trip in trips.data"
                        :key="trip.id"
                        class="cursor-pointer"
                        :class="tripRowClass(trip)"
                        @click="openTrip(trip)"
                    >
                        <td v-if="canDelete" class="px-3 py-2" @click.stop>
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300"
                                :checked="selected.includes(trip.id)"
                                :aria-label="`Select trip ${trip.id}`"
                                @change="toggleSelected(trip.id, ($event.target as HTMLInputElement).checked)"
                            >
                        </td>
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
                        <td :colspan="emptyColspan" class="px-4 py-6">
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
