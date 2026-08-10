<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Upload } from 'lucide-vue-next';
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

const dropActive = ref(false);
const fileError = ref<string | null>(null);

const acceptedExtensions = new Set([
    'csv',
    'txt',
    'xlsx',
    'xls',
    'pdf',
    'jpg',
    'jpeg',
    'png',
    'webp',
    'gif',
]);

const isAcceptedFile = (file: File) => {
    const name = file.name.toLowerCase();
    const ext = name.includes('.') ? name.slice(name.lastIndexOf('.') + 1) : '';
    if (ext && acceptedExtensions.has(ext)) {
        return true;
    }
    const type = (file.type || '').toLowerCase();
    return (
        type === 'text/csv'
        || type === 'text/plain'
        || type === 'application/pdf'
        || type.startsWith('image/')
        || type.includes('spreadsheet')
        || type.includes('excel')
    );
};

const setFile = (file: File | null) => {
    fileError.value = null;
    form.clearErrors('file');
    form.file = file;
};

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    if (file && !isAcceptedFile(file)) {
        setFile(null);
        fileError.value = 'Upload a CSV, Excel, PDF, or image file.';
        input.value = '';
        return;
    }
    setFile(file);
    input.value = '';
};

const onDragEnter = (event: DragEvent) => {
    event.preventDefault();
    dropActive.value = true;
};

const onDragOver = (event: DragEvent) => {
    event.preventDefault();
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'copy';
    }
    dropActive.value = true;
};

const onDragLeave = (event: DragEvent) => {
    event.preventDefault();
    const current = event.currentTarget as HTMLElement | null;
    const related = event.relatedTarget as Node | null;
    if (current && related && current.contains(related)) {
        return;
    }
    dropActive.value = false;
};

const onDrop = (event: DragEvent) => {
    event.preventDefault();
    dropActive.value = false;
    const file = event.dataTransfer?.files?.[0] ?? null;
    if (!file) {
        return;
    }
    if (!isAcceptedFile(file)) {
        setFile(null);
        fileError.value = 'Upload a CSV, Excel, PDF, or image file.';
        return;
    }
    setFile(file);
};

const clearFile = () => {
    setFile(null);
};

const formatFileSize = (bytes: number) => {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
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
                    <p class="mb-1.5 text-xs font-medium text-slate-500">Trip log file</p>
                    <label
                        class="flex min-h-28 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-4 py-6 text-center transition"
                        :class="dropActive
                            ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/20'
                            : 'border-slate-300 bg-slate-50 hover:border-slate-400 hover:bg-slate-100'"
                        @dragenter="onDragEnter"
                        @dragover="onDragOver"
                        @dragleave="onDragLeave"
                        @drop="onDrop"
                    >
                        <Upload class="h-5 w-5 shrink-0 text-slate-500" />
                        <span class="text-sm font-medium text-slate-800">
                            {{
                                dropActive
                                    ? 'Drop to upload'
                                    : form.file
                                        ? 'Replace file'
                                        : 'Drop trip log here'
                            }}
                        </span>
                        <span class="text-xs text-slate-500">
                            CSV, Excel, PDF, or image — click or drag · max 10MB
                        </span>
                        <input
                            type="file"
                            accept=".csv,.txt,.xlsx,.xls,.pdf,.jpg,.jpeg,.png,.webp,.gif,text/csv,text/plain,application/pdf,image/*"
                            class="hidden"
                            @change="onFileChange"
                        >
                    </label>
                    <div
                        v-if="form.file"
                        class="mt-3 flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900">{{ form.file.name }}</p>
                            <p class="text-xs text-slate-500">{{ formatFileSize(form.file.size) }}</p>
                        </div>
                        <AppButton type="button" variant="ghost" size="sm" @click="clearFile">
                            Remove
                        </AppButton>
                    </div>
                    <p class="mt-1.5 text-xs text-slate-500">
                        Brief GPS stops are consolidated into single trips.
                    </p>
                    <p v-if="fileError" class="mt-1.5 text-xs text-red-600">{{ fileError }}</p>
                    <p v-if="form.errors.file" class="mt-1.5 text-xs text-red-600">{{ form.errors.file }}</p>
                </div>

                <FormActions bordered>
                    <AppButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="!vehicles.length || !aiEnabled || !form.file"
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
