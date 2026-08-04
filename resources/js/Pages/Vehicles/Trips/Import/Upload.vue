<script setup lang="ts">
import { computed } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import { useTravelTabs } from '@/Composables/useFeatureTabs';

const travelTabs = useTravelTabs();
const page = usePage();

type VehicleOption = {
    id: number;
    name: string;
    registration_number: string | null;
};

const props = defineProps<{
    vehicles: VehicleOption[];
    prefill_vehicle_id: number | null;
}>();

const form = useForm<{
    vehicle_id: number | '';
    file: File | null;
}>({
    vehicle_id: props.prefill_vehicle_id ?? '',
    file: null,
});

const vehicleSelectOptions = computed(() =>
    props.vehicles.map((vehicle) => ({
        label: vehicle.registration_number
            ? `${vehicle.name} (${vehicle.registration_number})`
            : vehicle.name,
        value: String(vehicle.id),
    })),
);

const aiEnabled = computed(() => Boolean(page.props.ai_enabled));

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    form.file = input.files?.[0] ?? null;
};

const submit = () => {
    form.post(route('vehicles.trips.import.store'), {
        forceFormData: true,
    });
};
</script>

<template>
    <FeatureShell
        title="Travel"
        section="import"
        :tabs="travelTabs"
        document-title="Smart AI import"
        subtitle="Import Toyota fleet, GPS, or onboard log-book exports. Stops are merged into journeys and duplicates are skipped."
    >
        <AppCard class="mt-5">
            <form class="grid max-w-xl gap-5" @submit.prevent="submit">
                <p v-if="!aiEnabled" class="text-sm text-amber-700">
                    Configure an AI provider in Business settings before using smart AI import.
                </p>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-500">Vehicle</label>
                    <AppSelect
                        :model-value="form.vehicle_id === '' ? '' : String(form.vehicle_id)"
                        :options="vehicleSelectOptions"
                        placeholder="Select vehicle"
                        :disabled="!vehicles.length"
                        @update:model-value="form.vehicle_id = $event === '' ? '' : Number($event)"
                    />
                    <p v-if="form.errors.vehicle_id" class="mt-1.5 text-xs text-red-600">{{ form.errors.vehicle_id }}</p>
                    <p v-if="!vehicles.length" class="mt-1.5 text-xs text-amber-700">
                        Add a vehicle before importing trips.
                    </p>
                </div>

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-500">Trip log file</label>
                    <input
                        type="file"
                        accept=".csv,.txt,.xlsx,.xls,.pdf,.jpg,.jpeg,.png,.webp,text/csv,text/plain,application/pdf,image/*"
                        class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                        @change="onFileChange"
                    >
                    <p class="mt-1.5 text-xs text-slate-500">
                        CSV, Excel, PDF, or image — max 10MB. Brief GPS stops are consolidated into single trips.
                    </p>
                    <p v-if="form.errors.file" class="mt-1.5 text-xs text-red-600">{{ form.errors.file }}</p>
                </div>

                <FormActions bordered>
                    <AppButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="!vehicles.length || !aiEnabled"
                    >
                        {{ form.processing ? 'Scanning…' : 'Scan & preview' }}
                    </AppButton>
                    <AppButton
                        type="button"
                        variant="secondary"
                        :disabled="form.processing"
                        @click="router.visit(route('vehicles.trips.index'))"
                    >
                        Cancel
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </FeatureShell>
</template>
