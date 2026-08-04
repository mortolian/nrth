<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

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

type RetentionBand = 'daily' | 'weekly' | 'monthly' | 'yearly';

type BackupRunRow = {
    id: number;
    status: string;
    filename: string | null;
    created_at: string | null;
    completed_at: string | null;
    backed_up_at: string | null;
    file_size_bytes: number | null;
    download_url: string | null;
    error_message: string | null;
    mirror_warning: string | null;
    can_retry: boolean;
    source: 'manual' | 'scheduled';
    types: RetentionBand[];
};

type BackupLinks = {
    destinations_summary: string;
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
    recent_backups: BackupRunRow[];
    backup_schedule_hint: string;
    backup_links: BackupLinks | null;
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

const retentionBandLabel: Record<RetentionBand, string> = {
    daily: 'Daily',
    weekly: 'Weekly',
    monthly: 'Monthly',
    yearly: 'Yearly',
};

const retentionBandVariant: Record<RetentionBand, 'success' | 'info' | 'neutral' | 'warning' | 'default'> = {
    daily: 'info',
    weekly: 'success',
    monthly: 'warning',
    yearly: 'default',
};

const backupMonthGroups = computed(() => {
    const groups: Array<{
        key: string;
        label: string;
        runs: BackupRunRow[];
    }> = [];
    const indexByKey = new Map<string, number>();

    for (const run of props.recent_backups) {
        const stamp = run.backed_up_at ?? run.completed_at ?? run.created_at;
        const created = stamp ? new Date(stamp) : null;
        const valid = created && !Number.isNaN(created.getTime());
        const key = valid
            ? `${created.getFullYear()}-${String(created.getMonth() + 1).padStart(2, '0')}`
            : 'unknown';
        const label = valid
            ? created.toLocaleString(undefined, { month: 'long', year: 'numeric' })
            : 'Unknown date';

        let idx = indexByKey.get(key);
        if (idx === undefined) {
            idx = groups.length;
            indexByKey.set(key, idx);
            groups.push({ key, label, runs: [] });
        }
        groups[idx].runs.push(run);
    }

    return groups;
});

const backupDateLabel = (run: BackupRunRow): string => {
    const stamp = run.backed_up_at ?? run.completed_at ?? run.created_at;
    return stamp ? stamp.slice(0, 19).replace('T', ' ') : '—';
};

const backupRowActions = (run: BackupRunRow) => {
    const actions: Array<{ id: string; label: string }> = [];
    if (run.download_url) {
        actions.push({ id: 'download', label: 'Download' });
    }
    if (run.status === 'ready' && run.filename) {
        actions.push({ id: 'restore_guide', label: 'Restore guide' });
    }
    if (run.can_retry) {
        actions.push({ id: 'retry', label: 'Retry' });
    }
    actions.push({ id: 'delete', label: 'Delete' });
    return actions;
};

const onBackupAction = (run: BackupRunRow, actionId: string) => {
    if (actionId === 'download') {
        if (!run.download_url) {
            return;
        }
        const anchor = document.createElement('a');
        anchor.href = run.download_url;
        anchor.download = run.filename ?? '';
        anchor.setAttribute('data-inertia', 'false');
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        return;
    }
    if (actionId === 'restore_guide') {
        if (!run.filename) {
            return;
        }
        router.visit(route('backups-exports.restore', { filename: run.filename }));
        return;
    }
    if (actionId === 'retry') {
        retryBackup(run);
        return;
    }
    if (actionId === 'delete') {
        deleteBackup(run);
    }
};

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

const takeoutRowActions = (run: TakeoutRunRow) => {
    const actions: Array<{ id: string; label: string }> = [];
    if (run.download_url) {
        actions.push({ id: 'download', label: 'Download' });
    }
    if (run.can_retry) {
        actions.push({ id: 'retry', label: 'Retry' });
    }
    actions.push({ id: 'delete', label: 'Delete' });
    return actions;
};

const onTakeoutAction = (run: TakeoutRunRow, actionId: string) => {
    if (actionId === 'download') {
        if (!run.download_url) {
            return;
        }
        const anchor = document.createElement('a');
        anchor.href = run.download_url;
        anchor.download = '';
        anchor.setAttribute('data-inertia', 'false');
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        return;
    }
    if (actionId === 'retry') {
        retryTakeout(run);
        return;
    }
    if (actionId === 'delete') {
        deleteTakeout(run);
    }
};

const runBackup = () => {
    backupForm.post(route('backups-exports.backups.store'), { preserveScroll: true });
};

const retryBackup = (run: BackupRunRow) => {
    router.post(route('backups-exports.backups.retry', run.id), {}, { preserveScroll: true });
};

const deleteBackup = (run: BackupRunRow) => {
    const label = run.filename ?? `backup #${run.id}`;
    if (!confirm(`Delete ${label}? This cannot be undone.`)) {
        return;
    }
    router.delete(route('backups-exports.backups.destroy', run.id), { preserveScroll: true });
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

const backupBusy = computed(() =>
    props.recent_backups.some((run) => run.status === 'queued' || run.status === 'processing'),
);

const shouldPoll = computed(() => takeoutBusy.value || backupBusy.value);

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
            only: ['recent_takeouts', 'recent_backups'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
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

        <nav
            v-if="can_generate_takeout && can_manage_backups"
            class="mt-5 border-b border-slate-200"
            aria-label="Backups and exports sections"
        >
            <div class="-mb-px flex gap-6" role="tablist">
                <button
                    type="button"
                    role="tab"
                    id="backups-tab-takeout"
                    :aria-selected="section === 'takeout'"
                    aria-controls="backups-panel-takeout"
                    class="border-b-2 px-0.5 pb-3 text-sm font-medium transition"
                    :class="section === 'takeout'
                        ? 'border-brand-600 text-brand-800'
                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'"
                    @click="setSection('takeout')"
                >
                    Data takeout
                </button>
                <button
                    type="button"
                    role="tab"
                    id="backups-tab-backup"
                    :aria-selected="section === 'backup'"
                    aria-controls="backups-panel-backup"
                    class="border-b-2 px-0.5 pb-3 text-sm font-medium transition"
                    :class="section === 'backup'
                        ? 'border-brand-600 text-brand-800'
                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'"
                    @click="setSection('backup')"
                >
                    Instance backup
                </button>
            </div>
        </nav>

        <section
            v-if="section === 'takeout' && can_generate_takeout && period && preview"
            id="backups-panel-takeout"
            :role="can_manage_backups ? 'tabpanel' : undefined"
            :aria-labelledby="can_manage_backups ? 'backups-tab-takeout' : undefined"
        >
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

            <div class="mt-5">
                <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Pre-flight summary</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ period.from }} to {{ period.to }}</p>
                    </div>
                </div>
                <div v-if="hasGaps" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    <p class="font-medium">Warnings</p>
                    <ul class="mt-1 list-disc pl-4">
                        <li v-for="gap in preview.gaps" :key="gap">{{ gap }}</li>
                    </ul>
                </div>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <AppCard v-for="category in document_categories" :key="category.key">
                        <h3 class="text-base font-semibold text-slate-900">{{ category.label }}</h3>
                        <p class="mt-2 text-sm text-slate-600">Count: {{ category.count }}</p>
                        <p v-if="category.total > 0" class="text-sm text-slate-600">Total value: {{ formatCents(category.total) }}</p>
                        <p v-if="category.warning" class="mt-2 text-xs text-amber-700">{{ category.warning }}</p>
                    </AppCard>
                </div>
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
                                    <div class="inline-flex justify-end">
                                        <InvoiceRowActionsMenu
                                            :actions="takeoutRowActions(run)"
                                            :aria-label="`Actions for takeout ${run.from_date} to ${run.to_date}`"
                                            @select="(id) => onTakeoutAction(run, id)"
                                        />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </section>

        <section
            v-else-if="section === 'backup' && can_manage_backups"
            id="backups-panel-backup"
            :role="can_generate_takeout ? 'tabpanel' : undefined"
            :aria-labelledby="can_generate_takeout ? 'backups-tab-backup' : undefined"
        >
            <div class="mt-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">Instance backup</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Whole server backup (database + app files). Not a tax export.
                    </p>
                </div>
                <AppButton
                    variant="primary"
                    :disabled="backupForm.processing || backupBusy"
                    @click="runBackup"
                >
                    {{ backupForm.processing || backupBusy ? 'Preparing…' : 'Run backup now' }}
                </AppButton>
            </div>

            <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                {{ backup_schedule_hint }}
            </div>

            <div v-if="backup_links" class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <Link :href="route('backups-exports.destinations')" class="block">
                    <AppCard class="h-full cursor-pointer transition hover:border-slate-300 hover:bg-slate-50">
                        <h3 class="text-sm font-semibold text-slate-900">Offsite destinations</h3>
                        <p class="mt-1 text-xs text-slate-600">{{ backup_links.destinations_summary }}</p>
                    </AppCard>
                </Link>
                <Link :href="route('backups-exports.retention')" class="block">
                    <AppCard class="h-full cursor-pointer transition hover:border-slate-300 hover:bg-slate-50">
                        <h3 class="text-sm font-semibold text-slate-900">Backup retention</h3>
                        <p class="mt-1 text-xs text-slate-600">Daily, weekly, monthly, and yearly keep counts</p>
                    </AppCard>
                </Link>
                <Link :href="route('backups-exports.restore')" class="block">
                    <AppCard class="h-full cursor-pointer transition hover:border-slate-300 hover:bg-slate-50">
                        <h3 class="text-sm font-semibold text-slate-900">Instance restore guide</h3>
                        <p class="mt-1 text-xs text-slate-600">Generate a CLI restore script for a ready zip</p>
                    </AppCard>
                </Link>
            </div>

            <p class="mt-4 text-sm text-slate-600">
                Outbound email and operators live under
                <Link :href="route('settings.instance')" class="font-medium text-brand-700 hover:underline">
                    Settings → Instance
                </Link>.
            </p>

            <AppCard class="mt-5">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Recent backups</h3>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Grouped by month. Type badges are the retention roles on that zip
                            (one file can be Daily and Weekly, etc.).
                        </p>
                    </div>
                    <p v-if="backupBusy" class="text-xs text-slate-500">Refreshing status…</p>
                </div>
                <p v-if="recent_backups.length === 0" class="text-sm text-slate-500">No backups yet.</p>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-slate-600">
                                <th class="px-2 py-2 font-medium">Type</th>
                                <th class="px-2 py-2 font-medium">File</th>
                                <th class="px-2 py-2 font-medium">Status</th>
                                <th class="px-2 py-2 font-medium">Size</th>
                                <th class="px-2 py-2 font-medium">Created</th>
                                <th class="px-2 py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="group in backupMonthGroups" :key="group.key">
                                <tr class="bg-slate-50/90">
                                    <td colspan="6" class="px-2 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                        {{ group.label }}
                                        <span class="ml-1 font-normal normal-case text-slate-500">
                                            · {{ group.runs.length }} {{ group.runs.length === 1 ? 'backup' : 'backups' }}
                                        </span>
                                    </td>
                                </tr>
                                <tr
                                    v-for="run in group.runs"
                                    :key="run.id"
                                    class="border-b border-slate-100"
                                >
                                    <td class="px-2 py-2">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <AppBadge
                                                v-for="type in run.types"
                                                :key="type"
                                                :variant="retentionBandVariant[type]"
                                            >
                                                {{ retentionBandLabel[type] }}
                                            </AppBadge>
                                            <span v-if="!run.types?.length" class="text-xs text-slate-400">—</span>
                                            <AppBadge
                                                v-if="run.source === 'manual'"
                                                variant="neutral"
                                                title="Started with Run backup now"
                                            >
                                                Manual
                                            </AppBadge>
                                        </div>
                                    </td>
                                    <td class="px-2 py-2 font-mono text-xs">{{ run.filename ?? '—' }}</td>
                                    <td class="px-2 py-2">
                                        <span
                                            :class="run.status === 'failed' ? 'text-rose-600' : run.status === 'ready' ? 'text-brand-700' : 'text-slate-600'"
                                        >
                                            {{ statusLabel(run.status) }}
                                        </span>
                                        <p v-if="run.error_message" class="mt-0.5 text-xs text-rose-600">{{ run.error_message }}</p>
                                        <p v-if="run.mirror_warning" class="mt-0.5 text-xs text-amber-700">{{ run.mirror_warning }}</p>
                                    </td>
                                    <td class="px-2 py-2">{{ formatFileSize(run.file_size_bytes) }}</td>
                                    <td class="px-2 py-2">{{ backupDateLabel(run) }}</td>
                                    <td class="px-2 py-2 text-right">
                                        <div class="inline-flex justify-end">
                                            <InvoiceRowActionsMenu
                                                :actions="backupRowActions(run)"
                                                :aria-label="`Actions for ${run.filename ?? `backup #${run.id}`}`"
                                                @select="(id) => onBackupAction(run, id)"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </section>
    </AppLayout>
</template>
