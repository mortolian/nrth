<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

type TakeoutRunRow = {
    id: number;
    download_token: string;
    from_date: string;
    to_date: string;
    status: string;
    created_at: string | null;
    expires_at: string | null;
    file_size_bytes: number | null;
    download_url: string | null;
    error_message: string | null;
    can_retry: boolean;
};

type BackupRow = {
    filename: string;
    path: string;
    disk: string;
    date: string | null;
    size_bytes: number;
};

const props = defineProps<{
    section: 'takeout' | 'backup';
    can_generate_takeout: boolean;
    can_manage_backups: boolean;
    period: { from: string; to: string; preset: string } | null;
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
    } | null;
    document_categories: Array<{
        key: string;
        label: string;
        count: number;
        total: number;
        warning: string | null;
    }>;
    recent_takeouts: TakeoutRunRow[];
    backups: BackupRow[];
    backup_running: boolean;
    backup_last_error: string | null;
    backup_schedule_hint: string;
    latest_backup_at: string | null;
}>();

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const state = ref({
    preset: props.period?.preset || 'this_tax_year',
    from: props.period?.from || '',
    to: props.period?.to || '',
});

const takeoutForm = useForm({
    from_date: props.period?.from || '',
    to_date: props.period?.to || '',
});

const backupForm = useForm({});

watch(
    () => props.period,
    (period) => {
        if (!period) {
            return;
        }
        state.value = {
            preset: period.preset || 'this_tax_year',
            from: period.from,
            to: period.to,
        };
        takeoutForm.from_date = period.from;
        takeoutForm.to_date = period.to;
    },
);

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
        route('backups-exports.index'),
        {
            section: 'takeout',
            preset: state.value.preset,
            from: state.value.from,
            to: state.value.to,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const onPresetChange = (preset: string) => {
    state.value.preset = preset;
    if (preset !== 'custom') {
        applyPeriod();
    }
};

const setSection = (section: 'takeout' | 'backup') => {
    router.get(
        route('backups-exports.index'),
        {
            section,
            ...(section === 'takeout'
                ? { preset: state.value.preset, from: state.value.from, to: state.value.to }
                : {}),
        },
        { preserveState: false, preserveScroll: true },
    );
};

const generateTakeout = () => {
    takeoutForm.from_date = state.value.from;
    takeoutForm.to_date = state.value.to;
    takeoutForm.post(route('tax.takeouts.store'), {
        preserveScroll: true,
    });
};

const retryTakeout = (run: TakeoutRunRow) => {
    router.post(route('tax.takeouts.retry', run.download_token), {}, { preserveScroll: true });
};

const deleteTakeout = (run: TakeoutRunRow) => {
    if (!confirm('Delete this takeout and its zip file?')) {
        return;
    }
    router.delete(route('tax.takeouts.destroy', run.download_token), {
        data: { from: state.value.from, to: state.value.to },
        preserveScroll: true,
    });
};

const runBackup = () => {
    backupForm.post(route('backups-exports.backups.store'), { preserveScroll: true });
};

const deleteBackup = (filename: string) => {
    if (!confirm(`Delete backup ${filename}? This cannot be undone.`)) {
        return;
    }
    router.delete(route('backups-exports.backups.destroy', filename), { preserveScroll: true });
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

const hasGaps = computed(() => (props.preview?.gaps.length ?? 0) > 0);

const takeoutBusy = computed(() =>
    props.recent_takeouts.some((run) => run.status === 'queued' || run.status === 'processing'),
);

const shouldPoll = computed(() => takeoutBusy.value || props.backup_running);

let pollTimer: ReturnType<typeof setInterval> | null = null;

const startPolling = () => {
    if (pollTimer !== null) {
        return;
    }
    pollTimer = setInterval(() => {
        if (!shouldPoll.value) {
            return;
        }
        router.reload({
            only: ['recent_takeouts', 'backups', 'backup_running', 'backup_last_error', 'latest_backup_at'],
            preserveScroll: true,
            preserveState: true,
        });
    }, 4000);
};

const stopPolling = () => {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

watch(
    shouldPoll,
    (busy) => {
        if (busy) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

onMounted(() => {
    if (shouldPoll.value) {
        startPolling();
    }
});

onBeforeUnmount(() => {
    stopPolling();
});
</script>

<template>
    <AppLayout
        title="Backups & exports"
        :breadcrumbs="[{ label: 'Backups & exports' }]"
    >
        <PageHeader
            title="Backups & exports"
            subtitle="Tax data takeouts for your team, and whole-server backups for operators"
        />

        <div class="mt-5 flex flex-wrap gap-2 border-b border-slate-200 pb-2">
            <button
                v-if="can_generate_takeout"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                :class="section === 'takeout' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                @click="setSection('takeout')"
            >
                Data takeout
            </button>
            <button
                v-if="can_manage_backups"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                :class="section === 'backup' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                @click="setSection('backup')"
            >
                Instance backup
            </button>
        </div>

        <template v-if="section === 'takeout' && can_generate_takeout && period && preview">
            <div class="mt-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Data takeout</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Export figures and supporting documents for a date range (tax / audit). Not a server backup.
                    </p>
                </div>
                <AppButton
                    variant="primary"
                    :disabled="takeoutForm.processing || takeoutBusy"
                    @click="generateTakeout"
                >
                    {{ takeoutForm.processing || takeoutBusy ? 'Preparing…' : 'Generate takeout' }}
                </AppButton>
            </div>

            <AppCard class="mt-5">
                <h3 class="text-base font-semibold text-slate-900">Export period</h3>
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
                <h3 class="text-base font-semibold text-slate-900">Pre-flight summary</h3>
                <p class="mt-1 text-sm text-slate-600">{{ period.from }} to {{ period.to }}</p>
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
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="text-base font-semibold text-slate-900">Recent takeouts</h3>
                    <p v-if="takeoutBusy" class="text-xs text-slate-500">Refreshing status…</p>
                </div>
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
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <a
                                            v-if="run.download_url"
                                            :href="run.download_url"
                                            class="text-brand-700 hover:underline"
                                        >
                                            Download
                                        </a>
                                        <button
                                            v-if="run.can_retry"
                                            type="button"
                                            class="text-slate-700 hover:underline"
                                            @click="retryTakeout(run)"
                                        >
                                            Retry
                                        </button>
                                        <button
                                            type="button"
                                            class="text-rose-600 hover:underline"
                                            @click="deleteTakeout(run)"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </template>

        <template v-else-if="section === 'backup' && can_manage_backups">
            <div class="mt-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Instance backup</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Whole server backup (database + app files). Not a tax export.
                    </p>
                </div>
                <AppButton
                    variant="primary"
                    :disabled="backupForm.processing || backup_running"
                    @click="runBackup"
                >
                    {{ backup_running || backupForm.processing ? 'Backup running…' : 'Run backup now' }}
                </AppButton>
            </div>

            <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                {{ backup_schedule_hint }}
                <span v-if="latest_backup_at" class="mt-1 block text-xs text-slate-500">
                    Latest backup: {{ latest_backup_at.slice(0, 19).replace('T', ' ') }}
                </span>
                <p v-if="backup_running" class="mt-1 text-xs font-medium text-amber-700">A backup is in progress…</p>
                <p v-if="backup_last_error && !backup_running" class="mt-1 text-xs font-medium text-rose-700">
                    Last backup failed: {{ backup_last_error }}
                </p>
            </div>

            <AppCard class="mt-5">
                <h3 class="mb-3 text-base font-semibold text-slate-900">Stored backups</h3>
                <p v-if="backups.length === 0" class="text-sm text-slate-500">No backups found yet.</p>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-slate-600">
                                <th class="px-2 py-2 font-medium">File</th>
                                <th class="px-2 py-2 font-medium">Date</th>
                                <th class="px-2 py-2 font-medium">Size</th>
                                <th class="px-2 py-2 font-medium">Disk</th>
                                <th class="px-2 py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="backup in backups" :key="backup.filename" class="border-b border-slate-100">
                                <td class="px-2 py-2 font-mono text-xs">{{ backup.filename }}</td>
                                <td class="px-2 py-2">{{ backup.date ? backup.date.slice(0, 19).replace('T', ' ') : '—' }}</td>
                                <td class="px-2 py-2">{{ formatFileSize(backup.size_bytes) }}</td>
                                <td class="px-2 py-2">{{ backup.disk }}</td>
                                <td class="px-2 py-2 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a
                                            :href="route('backups-exports.backups.download', backup.filename)"
                                            class="text-brand-700 hover:underline"
                                        >
                                            Download
                                        </a>
                                        <button
                                            type="button"
                                            class="text-rose-600 hover:underline"
                                            @click="deleteBackup(backup.filename)"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </template>
    </AppLayout>
</template>
