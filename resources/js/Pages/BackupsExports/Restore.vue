<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';

type RestoreGuide = {
    backup_name: string;
    container_zip_dir: string;
    db_connection: string;
    db_database: string;
    db_username: string;
    archive_password_configured: boolean;
};

const props = defineProps<{
    restore_guide: RestoreGuide;
    ready_filenames: string[];
    selected_filename: string | null;
}>();

const restoreFilename = ref(props.selected_filename ?? '');
const restoreRuntime = ref<'compose' | 'sail'>('compose');
const restoreCopied = ref(false);

watch(
    () => props.selected_filename,
    (next) => {
        if (next) {
            restoreFilename.value = next;
        }
    },
);

watch(
    () => props.ready_filenames,
    (rows) => {
        if (!restoreFilename.value || !rows.includes(restoreFilename.value)) {
            restoreFilename.value = rows[0] ?? '';
        }
    },
);

const restoreBackupOptions = computed(() =>
    props.ready_filenames.map((filename) => ({
        label: filename,
        value: filename,
    })),
);

const restoreRuntimeOptions = [
    { label: 'Self-host (./scripts/compose.sh)', value: 'compose' },
    { label: 'Sail (./vendor/bin/sail)', value: 'sail' },
];

const restoreScript = computed(() => {
    const guide = props.restore_guide;
    const filename = restoreFilename.value;
    if (!filename) {
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

echo "Stopping app services (postgres stays up)…"
$RUNNER stop app scheduler
$RUNNER stop horizon 2>/dev/null || true
$RUNNER stop worker 2>/dev/null || true

echo "Recreating database $DB_NAME…"
$RUNNER exec -T postgres psql -U "$DB_USER" -d postgres -v ON_ERROR_STOP=1 -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$DB_NAME' AND pid <> pg_backend_pid();"
$RUNNER exec -T postgres psql -U "$DB_USER" -d postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \\"$DB_NAME\\";"
$RUNNER exec -T postgres psql -U "$DB_USER" -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE \\"$DB_NAME\\" OWNER \\"$DB_USER\\";"

echo "Importing dump…"
$RUNNER exec -T -i postgres psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 < "$HOST_DUMP"
rm -f "$HOST_DUMP"

echo "Starting services…"
$RUNNER start app scheduler
$RUNNER start horizon 2>/dev/null || true
$RUNNER start worker 2>/dev/null || true

echo "Cleaning up extract dir…"
$RUNNER exec -T app rm -rf "$WORKDIR"

echo "Optional: restore uploaded files from the zip (re-extract, then):"
echo "  $RUNNER exec -T app sh -c 'unzip -o $ZIP -d /tmp/nrth-files && cp -a /tmp/nrth-files/var/www/html/storage/app/private/. /var/www/html/storage/app/private/ && rm -rf /tmp/nrth-files'"
echo "(Skip if you only need the database.)"

echo "Done. Open the app and sign in. If assets look wrong, run ./scripts/update (self-host) or rebuild Vite."
`;
});

const copyRestoreScript = async () => {
    try {
        await navigator.clipboard.writeText(restoreScript.value);
        restoreCopied.value = true;
        window.setTimeout(() => {
            restoreCopied.value = false;
        }, 2000);
    } catch {
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
</script>

<template>
    <SettingsShell
        section="backups"
        title="Settings · Instance restore guide"
        subtitle="Generate a downtime-aware shell script — there is no one-click restore in the app"
    >
        <div class="mb-4">
            <Link
                :href="route('backups-exports.index', { section: 'backup' })"
                class="text-sm font-medium text-brand-700 hover:underline"
            >
                Back to instance backup
            </Link>
        </div>

        <AppCard>
            <p class="text-sm text-slate-600">
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
    </SettingsShell>
</template>
