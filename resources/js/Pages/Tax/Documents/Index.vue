<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFlash } from '@/composables/useFlash';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

type TakeoutRunRow = {
    id: number;
    from_date: string;
    to_date: string;
    status: string;
    created_at: string | null;
    expires_at: string | null;
    file_size_bytes: number | null;
    download_url: string | null;
    error_message: string | null;
};

const props = defineProps<{
    period: { from: string; to: string; preset: string };
    preview: {
        invoices_count: number;
        expenses_count: number;
        expense_receipts_count: number;
        expenses_missing_receipts: number;
        bank_statement_files: number;
        vat_periods_count: number;
        contracts_count: number;
        contracts_missing_signed_file: number;
        gaps: string[];
    };
    document_categories: Array<{
        key: string;
        label: string;
        count: number;
        total: number;
        warning: string | null;
    }>;
    recent_takeouts: TakeoutRunRow[];
    can_generate_takeout: boolean;
}>();

const { success: flashSuccess } = useFlash();
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const state = ref({
    preset: props.period.preset || 'this_tax_year',
    from: props.period.from,
    to: props.period.to,
});

const takeoutForm = useForm({
    from_date: props.period.from,
    to_date: props.period.to,
});

watch(
    () => [state.value.from, state.value.to],
    ([from, to]) => {
        takeoutForm.from_date = from;
        takeoutForm.to_date = to;
    },
);

const presetOptions = [
    { label: 'This tax year', value: 'this_tax_year' },
    { label: 'Previous tax year', value: 'previous_tax_year' },
    { label: 'Custom', value: 'custom' },
];

const applyPeriod = () => {
    router.get(
        route('tax.documents.index'),
        { ...state.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const onPresetChange = (preset: string) => {
    state.value.preset = preset;
    if (preset !== 'custom') {
        applyPeriod();
    }
};

const generateTakeout = () => {
    takeoutForm.from_date = state.value.from;
    takeoutForm.to_date = state.value.to;
    takeoutForm.post(route('tax.takeouts.store'), {
        preserveScroll: true,
    });
};

const formatFileSize = (bytes: number | null) => {
    if (!bytes || bytes <= 0) {
        return '—';
    }
    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const statusLabel = (status: string) => {
    const labels: Record<string, string> = {
        queued: 'Queued',
        processing: 'Preparing…',
        ready: 'Ready',
        failed: 'Failed',
        expired: 'Expired',
    };

    return labels[status] ?? status;
};

const hasGaps = computed(() => props.preview.gaps.length > 0);
</script>

<template>
    <AppLayout
        title="Tax Documents"
        :breadcrumbs="[
            { label: 'Tax' },
            { label: 'Documents' },
        ]"
    >
        <PageHeader title="Data takeout" subtitle="Export figures and supporting documents for a date range">
            <template #actions>
                <AppButton
                    v-if="can_generate_takeout"
                    variant="primary"
                    :disabled="takeoutForm.processing"
                    @click="generateTakeout"
                >
                    {{ takeoutForm.processing ? 'Queueing…' : 'Generate takeout' }}
                </AppButton>
            </template>
        </PageHeader>

        <p v-if="flashSuccess" class="mt-4 rounded-md border border-brand-200 bg-brand-50 px-3 py-2 text-sm text-brand-800">
            {{ flashSuccess }}
        </p>

        <AppCard class="mt-5">
            <h3 class="text-lg font-semibold text-slate-900">Export period</h3>
            <div class="mt-3 grid gap-3 md:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Preset</label>
                    <AppSelect
                        :model-value="state.preset"
                        :options="presetOptions"
                        @update:model-value="onPresetChange($event)"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">From</label>
                    <AppInput v-model="state.from" type="date" @change="state.preset = 'custom'" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">To</label>
                    <AppInput v-model="state.to" type="date" @change="state.preset = 'custom'" />
                </div>
                <div class="flex items-end">
                    <AppButton variant="secondary" class="w-full" @click="applyPeriod">Update preview</AppButton>
                </div>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <h3 class="text-lg font-semibold text-slate-900">Pre-flight summary</h3>
            <p class="mt-1 text-sm text-slate-600">
                {{ period.from }} to {{ period.to }}
            </p>
            <ul class="mt-3 space-y-1 text-sm text-slate-700">
                <li>{{ preview.invoices_count }} invoice(s)</li>
                <li>{{ preview.expenses_count }} expense(s), {{ preview.expense_receipts_count }} with receipts</li>
                <li>{{ preview.bank_statement_files }} bank statement file(s)</li>
                <li>{{ preview.contracts_count }} contract(s)</li>
                <li>{{ preview.vat_periods_count }} VAT period(s)</li>
            </ul>
            <div v-if="hasGaps" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                <p class="font-medium">Warnings</p>
                <ul class="mt-1 list-disc pl-4">
                    <li v-for="gap in preview.gaps" :key="gap">{{ gap }}</li>
                </ul>
            </div>
        </AppCard>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <AppCard v-for="category in document_categories" :key="category.key">
                <h3 class="text-base font-semibold text-slate-900">{{ category.label }}</h3>
                <p class="mt-2 text-sm text-slate-600">Count: {{ category.count }}</p>
                <p v-if="category.total > 0" class="text-sm text-slate-600">Total value: {{ formatCents(category.total) }}</p>
                <p v-if="category.warning" class="mt-2 text-xs text-amber-700">{{ category.warning }}</p>
            </AppCard>
        </div>

        <AppCard class="mt-5">
            <h3 class="mb-3 text-lg font-semibold text-slate-900">Recent takeouts</h3>
            <p v-if="recent_takeouts.length === 0" class="text-sm text-slate-500">No takeouts yet for this team.</p>
            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-600">
                            <th class="px-2 py-2 font-medium">Period</th>
                            <th class="px-2 py-2 font-medium">Status</th>
                            <th class="px-2 py-2 font-medium">Size</th>
                            <th class="px-2 py-2 font-medium">Expires</th>
                            <th class="px-2 py-2 font-medium" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="run in recent_takeouts" :key="run.id" class="border-b border-slate-100">
                            <td class="px-2 py-2">{{ run.from_date }} → {{ run.to_date }}</td>
                            <td class="px-2 py-2">
                                <span
                                    :class="run.status === 'failed' ? 'text-rose-600' : run.status === 'ready' ? 'text-brand-700' : 'text-slate-600'"
                                >
                                    {{ statusLabel(run.status) }}
                                </span>
                                <p v-if="run.error_message" class="mt-0.5 text-xs text-rose-600">{{ run.error_message }}</p>
                            </td>
                            <td class="px-2 py-2">{{ formatFileSize(run.file_size_bytes) }}</td>
                            <td class="px-2 py-2">{{ run.expires_at ? run.expires_at.slice(0, 10) : '—' }}</td>
                            <td class="px-2 py-2 text-right">
                                <a
                                    v-if="run.download_url"
                                    :href="run.download_url"
                                    class="text-brand-700 hover:underline"
                                >
                                    Download
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>
    </AppLayout>
</template>
