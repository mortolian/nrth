<script setup lang="ts">
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import { useTravelTabs } from '@/Composables/useFeatureTabs';

const travelTabs = useTravelTabs();
const page = usePage();

type ImportRow = {
    id: number;
    original_filename: string;
    parser: string;
    status: string;
    imported_rows: number | null;
    can_undo: boolean;
    created_at: string | null;
    vehicle: {
        id: number;
        name: string;
        registration_number: string | null;
    } | null;
};

type VehicleOption = {
    id: number;
    name: string;
    registration_number: string | null;
};

const props = defineProps<{
    imports: {
        data: ImportRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    vehicles: VehicleOption[];
    filters: {
        vehicle_id: number | null;
    };
}>();

const filters = ref({
    vehicle_id: props.filters.vehicle_id ? String(props.filters.vehicle_id) : 'all',
});

const aiEnabled = Boolean(page.props.ai_enabled);

const vehicleLabel = (vehicle: ImportRow['vehicle'] | VehicleOption | null) => {
    if (!vehicle) {
        return '—';
    }
    return vehicle.registration_number
        ? `${vehicle.name} (${vehicle.registration_number})`
        : vehicle.name;
};

const statusLabel = (status: string) => {
    switch (status) {
        case 'imported':
            return 'Imported';
        case 'undone':
            return 'Undone';
        default:
            return status;
    }
};

const parserLabel = (parser: string) => {
    if (parser === 'telematics') {
        return 'Telematics';
    }
    if (parser === 'ai') {
        return 'AI';
    }
    return parser;
};

const formatDate = (value: string | null) => {
    if (!value) {
        return '—';
    }
    try {
        return new Date(value).toLocaleString();
    } catch {
        return value;
    }
};

const applyFilters = (pageNum = 1) => {
    router.get(route('vehicles.trips.imports.index'), {
        vehicle_id: filters.value.vehicle_id === 'all' ? undefined : filters.value.vehicle_id,
        page: pageNum,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const undoImport = (row: ImportRow) => {
    if (!row.can_undo) {
        return;
    }
    if (!window.confirm(
        `Undo import of “${row.original_filename}”? All trips from this import will be deleted.`,
    )) {
        return;
    }
    router.post(route('vehicles.trips.imports.undo', row.id));
};
</script>

<template>
    <FeatureShell
        title="Travel"
        section="import-history"
        :tabs="travelTabs"
        document-title="Trip import history"
        subtitle="Undo Smart AI imports that created the wrong trips"
    >
        <template #actions>
            <AppButton variant="secondary" @click="router.visit(route('vehicles.trips.index'))">
                Log book
            </AppButton>
            <AppButton
                v-if="aiEnabled"
                variant="primary"
                @click="router.visit(route('vehicles.trips.import.create'))"
            >
                Smart AI import
            </AppButton>
        </template>

        <AppCard>
            <form class="mb-4 max-w-sm" @submit.prevent="applyFilters()">
                <label class="mb-1 block text-xs font-medium text-slate-500">Vehicle</label>
                <AppSelect
                    :model-value="filters.vehicle_id"
                    :options="[
                        { label: 'All vehicles', value: 'all' },
                        ...vehicles.map((vehicle) => ({
                            label: vehicleLabel(vehicle),
                            value: String(vehicle.id),
                        })),
                    ]"
                    @update:model-value="(value) => { filters.vehicle_id = value; applyFilters(); }"
                />
            </form>

            <AppTable
                v-if="imports.data.length"
                embedded
                table-class="text-sm"
                :show-pagination="false"
                :columns="[
                    { key: 'date', label: 'Date' },
                    { key: 'file', label: 'File' },
                    { key: 'vehicle', label: 'Vehicle' },
                    { key: 'status', label: 'Status' },
                    { key: 'rows', label: 'Rows' },
                    { key: 'actions', label: '', widthClass: 'text-right' },
                ]"
            >
                <tr v-for="row in imports.data" :key="row.id" class="border-t border-slate-100">
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ formatDate(row.created_at) }}</td>
                    <td class="px-3 py-2 text-slate-900">
                        <div class="font-medium">{{ row.original_filename }}</div>
                        <div class="text-xs uppercase text-slate-500">{{ parserLabel(row.parser) }}</div>
                    </td>
                    <td class="px-3 py-2 text-slate-600">{{ vehicleLabel(row.vehicle) }}</td>
                    <td class="px-3 py-2 text-slate-600">{{ statusLabel(row.status) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-600">
                        {{ row.imported_rows ?? 0 }} imported
                    </td>
                    <td class="px-3 py-2 text-right">
                        <AppButton
                            v-if="row.can_undo"
                            type="button"
                            variant="secondary"
                            size="sm"
                            @click="undoImport(row)"
                        >
                            Undo
                        </AppButton>
                    </td>
                </tr>
            </AppTable>

            <EmptyState
                v-else
                title="No imports yet"
                description="Confirmed Smart AI imports appear here so you can undo a whole batch if needed."
            >
                <template v-if="aiEnabled" #action>
                    <AppButton variant="primary" @click="router.visit(route('vehicles.trips.import.create'))">
                        Smart AI import
                    </AppButton>
                </template>
            </EmptyState>

            <div
                v-if="imports.last_page > 1"
                class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4"
            >
                <p class="text-sm text-slate-500">
                    Page {{ imports.current_page }} of {{ imports.last_page }} · {{ imports.total }} total
                </p>
                <div class="flex gap-2">
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :disabled="imports.current_page <= 1"
                        @click="applyFilters(imports.current_page - 1)"
                    >
                        Previous
                    </AppButton>
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :disabled="imports.current_page >= imports.last_page"
                        @click="applyFilters(imports.current_page + 1)"
                    >
                        Next
                    </AppButton>
                </div>
            </div>
        </AppCard>
    </FeatureShell>
</template>
