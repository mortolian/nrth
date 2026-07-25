<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
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

type BackupRunRow = {
    id: number;
    status: string;
    filename: string | null;
    created_at: string | null;
    completed_at: string | null;
    file_size_bytes: number | null;
    download_url: string | null;
    error_message: string | null;
    can_retry: boolean;
};

type RestoreGuide = {
    backup_name: string;
    container_zip_dir: string;
    db_connection: string;
    db_database: string;
    db_username: string;
    archive_password_configured: boolean;
};

type OperatorRow = {
    id: number | null;
    name: string | null;
    email: string;
    source: string;
    can_remove: boolean;
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
    operators: OperatorRow[];
    env_break_glass_configured: boolean;
    backup_schedule_hint: string;
    restore_guide: RestoreGuide | null;
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

const operatorForm = useForm({
    email: '',
});

const addOperator = () => {
    operatorForm.post(route('settings.instance.operators.store'), {
        preserveScroll: true,
        onSuccess: () => operatorForm.reset('email'),
    });
};

const removeOperator = (row: OperatorRow) => {
    if (!row.id || !row.can_remove) {
        return;
    }
    if (!confirm(`Remove ${row.email} as an instance operator?`)) {
        return;
    }
    useForm({}).delete(route('settings.instance.operators.destroy', row.id), {
        preserveScroll: true,
    });
};

const operatorSourceLabel = (source: string) => {
    if (source === 'environment') {
        return 'Environment';
    }
    if (source === 'database+environment') {
        return 'Database + environment';
    }

    return 'Database';
};

const readyBackups = computed(() =>
    props.recent_backups.filter((run) => run.status === 'ready' && !!run.filename),
);

const restoreFilename = ref(readyBackups.value[0]?.filename ?? '');
const restoreRuntime = ref<'compose' | 'sail'>('compose');
const restoreCopied = ref(false);

watch(
    readyBackups,
    (rows) => {
        if (!restoreFilename.value || !rows.some((run) => run.filename === restoreFilename.value)) {
            restoreFilename.value = rows[0]?.filename ?? '';
        }
    },
    { immediate: true },
);

const restoreBackupOptions = computed(() =>
    readyBackups.value.map((run) => ({
        label: run.filename ?? `backup #${run.id}`,
        value: run.filename ?? '',
    })),
);

const restoreRuntimeOptions = [
    { label: 'Self-host (./scripts/compose.sh)', value: 'compose' },
    { label: 'Sail (./vendor/bin/sail)', value: 'sail' },
];

const restoreScript = computed(() => {
    const guide = props.restore_guide;
    const filename = restoreFilename.value;
    if (!guide || !filename) {
        return '# Select a ready backup to generate restore commands.';
    }

    const runner = restoreRuntime.value === 'sail' ? './vendor/bin/sail' : './scripts/compose.sh';
    const zipPath = `/var/www/html/${guide.container_zip_dir}/${filename}`;
    const db = guide.db_database;
    const user = guide.db_username;
    const passwordNote = guide.archive_password_configured
        ? '# Archive encryption is enabled (BACKUP_ARCHIVE_PASSWORD). If unzip fails, decrypt with that password first.\n'
        : '';

    return `#!/usr/bin/env bash
# nrth instance restore (generated) — review before running
# Backup: ${filename}
# WARNING: replaces the live Postgres database. Expect downtime.
# App code should still come from git / ./scripts/update — this restores data, not a full OS image.
set -euo pipefail

cd "\${INSTALL_DIR:-\$(pwd)}"
RUNNER="${runner}"
ZIP="${zipPath}"
WORKDIR="/tmp/nrth-restore-\$\$"
HOST_DUMP="\$(pwd)/.nrth-restore-\$\$-dump.sql"
DB_NAME="${db}"
DB_USER="${user}"

${passwordNote}echo "Extracting backup zip (app still running)…"
$RUNNER exec -T app mkdir -p "$WORKDIR"
$RUNNER exec -T app unzip -o "$ZIP" -d "$WORKDIR"

DUMP="$($RUNNER exec -T app sh -c "ls $WORKDIR/db-dumps/*.sql 2>/dev/null | head -1")"
if [ -z "$DUMP" ]; then
  echo "No SQL dump found in $WORKDIR/db-dumps — aborting." >&2
  exit 1
fi
echo "Using dump: $DUMP"
$RUNNER exec -T app cat "$DUMP" > "$HOST_DUMP"

echo "Stopping app + worker + scheduler (postgres stays up)…"
$RUNNER stop app worker scheduler

echo "Recreating database $DB_NAME…"
$RUNNER exec -T postgres psql -U "$DB_USER" -d postgres -v ON_ERROR_STOP=1 -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB_NAME' AND pid <> pg_backend_pid();"
$RUNNER exec -T postgres psql -U "$DB_USER" -d postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \\"$DB_NAME\\";"
$RUNNER exec -T postgres psql -U "$DB_USER" -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE \\"$DB_NAME\\" OWNER \\"$DB_USER\\";"

echo "Importing dump…"
$RUNNER exec -T -i postgres psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 < "$HOST_DUMP"
rm -f "$HOST_DUMP"

echo "Starting services…"
$RUNNER start app worker scheduler

echo "Cleaning up extract dir…"
$RUNNER exec -T app rm -rf "$WORKDIR"

echo "Optional: restore uploaded files from the zip (re-extract, then):"
echo "  $RUNNER exec -T app sh -c 'unzip -o $ZIP -d /tmp/nrth-files && cp -a /tmp/nrth-files/var/www/html/storage/app/private/. /var/www/html/storage/app/private/ && rm -rf /tmp/nrth-files'"
echo "(Skip if you only need the database.)"

echo "Done. Open the app and sign in. If assets look wrong, run ./scripts/update (self-host) or rebuild Vite."
`;
});

const useBackupForRestore = (run: BackupRunRow) => {
    if (!run.filename) {
        return;
    }
    restoreFilename.value = run.filename;
    window.requestAnimationFrame(() => {
        document.getElementById('instance-restore-guide')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
};

const copyRestoreScript = async () => {
    try {
        await navigator.clipboard.writeText(restoreScript.value);
        restoreCopied.value = true;
        window.setTimeout(() => {
            restoreCopied.value = false;
        }, 2000);
    } catch {
        // Fallback for older browsers / insecure context
        const area = document.createElement('textarea');
        area.value = restoreScript.value;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        document.body.removeChild(area);
        restoreCopied.value = true;
        window.setTimeout(() => {
            restoreCopied.value = false;
        }, 2000);
    }
};

const downloadRestoreScript = () => {
    const blob = new Blob([restoreScript.value], { type: 'text/x-shellscript' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    const stamp = (restoreFilename.value || 'backup').replace(/\.zip$/i, '');
    anchor.href = url;
    anchor.download = `nrth-restore-${stamp}.sh`;
    anchor.click();
    URL.revokeObjectURL(url);
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
            // Keep form state, but always replace polled lists from the server.
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
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <a
                                            v-if="run.download_url"
                                            :href="run.download_url"
                                            class="text-brand-700 hover:underline"
                                            download
                                            data-inertia="false"
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

            <AppCard class="mt-5">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="text-base font-semibold text-slate-900">Recent backups</h3>
                    <p v-if="backupBusy" class="text-xs text-slate-500">Refreshing status…</p>
                </div>
                <p v-if="recent_backups.length === 0" class="text-sm text-slate-500">No backups yet.</p>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-slate-600">
                                <th class="px-2 py-2 font-medium">File</th>
                                <th class="px-2 py-2 font-medium">Status</th>
                                <th class="px-2 py-2 font-medium">Size</th>
                                <th class="px-2 py-2 font-medium">Created</th>
                                <th class="px-2 py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="run in recent_backups" :key="run.id" class="border-b border-slate-100">
                                <td class="px-2 py-2 font-mono text-xs">{{ run.filename ?? '—' }}</td>
                                <td class="px-2 py-2">
                                    <span
                                        :class="run.status === 'failed' ? 'text-rose-600' : run.status === 'ready' ? 'text-brand-700' : 'text-slate-600'"
                                    >
                                        {{ statusLabel(run.status) }}
                                    </span>
                                    <p v-if="run.error_message" class="mt-0.5 text-xs text-rose-600">{{ run.error_message }}</p>
                                </td>
                                <td class="px-2 py-2">{{ formatFileSize(run.file_size_bytes) }}</td>
                                <td class="px-2 py-2">{{ run.created_at ? run.created_at.slice(0, 19).replace('T', ' ') : '—' }}</td>
                                <td class="px-2 py-2 text-right">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <a
                                            v-if="run.download_url"
                                            :href="run.download_url"
                                            class="text-brand-700 hover:underline"
                                            download
                                            data-inertia="false"
                                        >
                                            Download
                                        </a>
                                        <button
                                            v-if="run.status === 'ready' && run.filename"
                                            type="button"
                                            class="text-slate-700 hover:underline"
                                            @click="useBackupForRestore(run)"
                                        >
                                            Restore guide
                                        </button>
                                        <button
                                            v-if="run.can_retry"
                                            type="button"
                                            class="text-slate-700 hover:underline"
                                            @click="retryBackup(run)"
                                        >
                                            Retry
                                        </button>
                                        <button
                                            type="button"
                                            class="text-rose-600 hover:underline"
                                            @click="deleteBackup(run)"
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

            <AppCard v-if="restore_guide" id="instance-restore-guide" class="mt-5">
                <h3 class="text-base font-semibold text-slate-900">Instance restore guide</h3>
                <p class="mt-1 text-sm text-slate-600">
                    One-click restore is not available in the app (it would overwrite the live database while the app is running).
                    Pick a ready backup to generate a downtime-aware shell script for your host.
                </p>

                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    <p class="font-medium">Before you run anything</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-4">
                        <li>This replaces the live Postgres database. Everyone will be signed out.</li>
                        <li>Prefer a quiet maintenance window; stop public traffic first if the instance is exposed.</li>
                        <li>Keep a copy of the current backup zip before overwriting data.</li>
                        <li>App code still comes from git — the script restores data (and optionally storage files).</li>
                    </ul>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Backup zip</label>
                        <AppSelect
                            v-model="restoreFilename"
                            :options="restoreBackupOptions"
                            :disabled="restoreBackupOptions.length === 0"
                            placeholder="No ready backups"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Host runner</label>
                        <AppSelect v-model="restoreRuntime" :options="restoreRuntimeOptions" />
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :disabled="!restoreFilename"
                        @click="copyRestoreScript"
                    >
                        {{ restoreCopied ? 'Copied' : 'Copy script' }}
                    </AppButton>
                    <AppButton
                        variant="ghost"
                        size="sm"
                        :disabled="!restoreFilename"
                        @click="downloadRestoreScript"
                    >
                        Download .sh
                    </AppButton>
                </div>

                <pre class="mt-4 max-h-96 overflow-auto whitespace-pre-wrap rounded-md border border-slate-200 bg-slate-950 p-3 text-xs leading-relaxed text-slate-100">{{ restoreScript }}</pre>
            </AppCard>

            <AppCard id="instance-operators" class="mt-5">
                <h3 class="text-base font-semibold text-slate-900">Instance operators</h3>
                <p class="mt-1 text-sm text-slate-600">
                    Who can manage whole-server backups for this install. Separate from business ownership.
                    The first registered user is promoted automatically; add others only when you need them.
                </p>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-slate-600">
                                <th class="px-2 py-2 font-medium">Name</th>
                                <th class="px-2 py-2 font-medium">Email</th>
                                <th class="px-2 py-2 font-medium">Source</th>
                                <th class="px-2 py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in operators" :key="`${row.source}-${row.email}`" class="border-b border-slate-100">
                                <td class="px-2 py-2">{{ row.name || '—' }}</td>
                                <td class="px-2 py-2">{{ row.email }}</td>
                                <td class="px-2 py-2">{{ operatorSourceLabel(row.source) }}</td>
                                <td class="px-2 py-2 text-right">
                                    <button
                                        v-if="row.can_remove && row.id"
                                        type="button"
                                        class="text-rose-600 hover:underline"
                                        @click="removeOperator(row)"
                                    >
                                        Remove
                                    </button>
                                    <span v-else-if="row.source === 'environment'" class="text-xs text-slate-500">
                                        Edit NRTH_OPERATOR_EMAILS in .env
                                    </span>
                                    <span v-else class="text-xs text-slate-500">Last operator</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <form class="mt-5 grid gap-3 md:grid-cols-[1fr_auto] md:items-end" @submit.prevent="addOperator">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Add operator by email</label>
                        <AppInput v-model="operatorForm.email" type="email" required placeholder="user@example.com" />
                        <p v-if="operatorForm.errors.email" class="mt-1 text-xs text-rose-600">{{ operatorForm.errors.email }}</p>
                    </div>
                    <AppButton type="submit" variant="primary" :disabled="operatorForm.processing">
                        {{ operatorForm.processing ? 'Adding…' : 'Add operator' }}
                    </AppButton>
                </form>

                <p v-if="env_break_glass_configured" class="mt-3 text-xs text-slate-500">
                    Break-glass emails from NRTH_OPERATOR_EMAILS are active. You can clear that env var once database operators are set.
                </p>
            </AppCard>
        </section>
    </AppLayout>
</template>
