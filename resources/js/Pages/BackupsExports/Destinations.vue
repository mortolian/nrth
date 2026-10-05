<script setup lang="ts">
import { computed, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';

type BackupDestinations = {
    s3: {
        enabled: boolean;
        key_set: boolean;
        secret_set: boolean;
        region: string;
        bucket: string;
        endpoint: string | null;
        use_path_style_endpoint: boolean;
        root: string;
    };
    path: {
        enabled: boolean;
        root: string;
    };
    active_disks: string[];
};

const props = defineProps<{
    backup_destinations: BackupDestinations;
}>();

const destinationsForm = useForm({
    s3: {
        enabled: props.backup_destinations.s3.enabled,
        key: '',
        secret: '',
        region: props.backup_destinations.s3.region,
        bucket: props.backup_destinations.s3.bucket,
        endpoint: props.backup_destinations.s3.endpoint ?? '',
        use_path_style_endpoint: props.backup_destinations.s3.use_path_style_endpoint,
        root: props.backup_destinations.s3.root,
    },
    path: {
        enabled: props.backup_destinations.path.enabled,
        root: props.backup_destinations.path.root,
    },
});

watch(
    () => props.backup_destinations,
    (next, prev) => {
        if (prev && JSON.stringify(prev) === JSON.stringify(next)) {
            return;
        }

        destinationsForm.s3.enabled = next.s3.enabled;
        destinationsForm.s3.region = next.s3.region;
        destinationsForm.s3.bucket = next.s3.bucket;
        destinationsForm.s3.endpoint = next.s3.endpoint ?? '';
        destinationsForm.s3.use_path_style_endpoint = next.s3.use_path_style_endpoint;
        destinationsForm.s3.root = next.s3.root;
        destinationsForm.s3.key = '';
        destinationsForm.s3.secret = '';
        destinationsForm.path.enabled = next.path.enabled;
        destinationsForm.path.root = next.path.root;
        destinationsForm.clearErrors();
    },
);

const saveDestinations = () => {
    destinationsForm.put(route('settings.instance.backup-destinations.update'), {
        preserveScroll: true,
    });
};

const testS3Form = useForm({});
const testPathForm = useForm({});

const testS3 = () => {
    testS3Form
        .transform(() => ({
            key: destinationsForm.s3.key || undefined,
            secret: destinationsForm.s3.secret || undefined,
            region: destinationsForm.s3.region,
            bucket: destinationsForm.s3.bucket,
            endpoint: destinationsForm.s3.endpoint || undefined,
            use_path_style_endpoint: destinationsForm.s3.use_path_style_endpoint,
            root: destinationsForm.s3.root || undefined,
        }))
        .post(route('settings.instance.backup-destinations.test-s3'), {
            preserveScroll: true,
            preserveState: true,
        });
};

const testPath = () => {
    testPathForm
        .transform(() => ({
            root: destinationsForm.path.root || undefined,
        }))
        .post(route('settings.instance.backup-destinations.test-path'), {
            preserveScroll: true,
            preserveState: true,
        });
};

const pathRootError = computed(() =>
    destinationsForm.errors['path.root']
    || testPathForm.errors['path.root']
    || testPathForm.errors.root
    || '',
);

const s3BucketError = computed(() =>
    destinationsForm.errors['s3.bucket']
    || testS3Form.errors['s3.bucket']
    || '',
);
</script>

<template>
    <SettingsShell
        section="backups"
        title="Settings · Offsite destinations"
        subtitle="Mirror each local backup zip to S3-compatible storage or a path/NFS mount"
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
                Each backup is always stored locally, and also written to every enabled offsite target.
                Leave access key / secret blank when saving to keep the stored values.
                For NFS, mount the share into the app and scheduler containers, then set that absolute path.
            </p>

            <form class="mt-4 space-y-6" @submit.prevent="saveDestinations">
                <div class="rounded-md border border-slate-200 p-4">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-800">
                        <input v-model="destinationsForm.s3.enabled" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                        S3-compatible object storage
                    </label>
                    <p class="mt-1 text-xs text-slate-500">
                        AWS S3, Cloudflare R2, MinIO, and other S3 APIs.
                        <span v-if="backup_destinations.s3.key_set || backup_destinations.s3.secret_set">Credentials are saved.</span>
                    </p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500">Access key</label>
                            <AppInput v-model="destinationsForm.s3.key" type="text" autocomplete="off" :placeholder="backup_destinations.s3.key_set ? '•••••••• (unchanged)' : ''" />
                            <p v-if="destinationsForm.errors['s3.key']" class="mt-1 text-xs text-rose-600">{{ destinationsForm.errors['s3.key'] }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500">Secret</label>
                            <AppInput v-model="destinationsForm.s3.secret" type="password" autocomplete="new-password" :placeholder="backup_destinations.s3.secret_set ? '•••••••• (unchanged)' : ''" />
                            <p v-if="destinationsForm.errors['s3.secret']" class="mt-1 text-xs text-rose-600">{{ destinationsForm.errors['s3.secret'] }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500">Region</label>
                            <AppInput v-model="destinationsForm.s3.region" type="text" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500">Bucket</label>
                            <AppInput v-model="destinationsForm.s3.bucket" type="text" />
                            <p v-if="s3BucketError" class="mt-1 text-xs text-rose-600">{{ s3BucketError }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500">Endpoint (optional)</label>
                            <AppInput v-model="destinationsForm.s3.endpoint" type="text" placeholder="https://…" />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500">Prefix / root (optional)</label>
                            <AppInput v-model="destinationsForm.s3.root" type="text" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input v-model="destinationsForm.s3.use_path_style_endpoint" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                                Use path-style endpoint (common for MinIO)
                            </label>
                        </div>
                    </div>
                    <div class="mt-3">
                        <AppButton type="button" variant="secondary" size="sm" :loading="testS3Form.processing" @click="testS3">
                            {{ testS3Form.processing ? 'Testing…' : 'Test S3' }}
                        </AppButton>
                    </div>
                </div>

                <div class="rounded-md border border-slate-200 p-4">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-800">
                        <input v-model="destinationsForm.path.enabled" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                        Path / NFS directory
                    </label>
                    <p class="mt-1 text-xs text-slate-500">Absolute path inside the container (for example an NFS mount).</p>
                    <div class="mt-3">
                        <label class="mb-1.5 block text-xs font-medium text-slate-500">Absolute path</label>
                        <AppInput v-model="destinationsForm.path.root" type="text" placeholder="/mnt/backups" />
                        <p v-if="pathRootError" class="mt-1 text-xs text-rose-600">{{ pathRootError }}</p>
                    </div>
                    <div class="mt-3">
                        <AppButton type="button" variant="secondary" size="sm" :loading="testPathForm.processing" @click="testPath">
                            {{ testPathForm.processing ? 'Testing…' : 'Test path' }}
                        </AppButton>
                    </div>

                    <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3 text-xs leading-relaxed text-slate-600">
                        <p class="font-medium text-slate-800">NFS mount</p>
                        <p class="mt-1">
                            Mount the share on the Docker host, then attach that folder to the app, Horizon, and scheduler containers. The path above is the path inside those containers.
                        </p>
                        <ol class="mt-2 list-decimal space-y-2 pl-4">
                            <li>
                                On the host, mount the export. Add the same mount to <code class="rounded bg-white px-1 py-0.5 font-mono text-[11px] text-slate-700">/etc/fstab</code> so it returns after a reboot.
                                <pre class="mt-1 overflow-x-auto rounded border border-slate-200 bg-white px-2 py-1.5 font-mono text-[11px] leading-relaxed text-slate-800">sudo mkdir -p /mnt/nas/nrth-backups
sudo mount -t nfs -o rw,nolock,nfsvers=4 192.168.1.50:/export/nrth-backups /mnt/nas/nrth-backups</pre>
                            </li>
                            <li>
                                Create <code class="rounded bg-white px-1 py-0.5 font-mono text-[11px] text-slate-700">compose.override.yaml</code> next to <code class="rounded bg-white px-1 py-0.5 font-mono text-[11px] text-slate-700">compose.yaml</code>.
                                <pre class="mt-1 overflow-x-auto rounded border border-slate-200 bg-white px-2 py-1.5 font-mono text-[11px] leading-relaxed text-slate-800">services:
  app:
    volumes:
      - /mnt/nas/nrth-backups:/mnt/backups
  horizon:
    volumes:
      - /mnt/nas/nrth-backups:/mnt/backups
  scheduler:
    volumes:
      - /mnt/nas/nrth-backups:/mnt/backups</pre>
                            </li>
                            <li>
                                Recreate the three services.
                                <pre class="mt-1 overflow-x-auto rounded border border-slate-200 bg-white px-2 py-1.5 font-mono text-[11px] leading-relaxed text-slate-800">./scripts/compose.sh up -d --force-recreate app horizon scheduler</pre>
                            </li>
                            <li>
                                Set the path above to <code class="rounded bg-white px-1 py-0.5 font-mono text-[11px] text-slate-700">/mnt/backups</code>, test it, then save.
                            </li>
                        </ol>
                        <p class="mt-2">
                            These containers run as root. If the export uses root_squash, writes appear as nobody and the path test fails. Allow writes on a backup-only export.
                        </p>
                    </div>
                </div>

                <FormActions class="!mt-2">
                    <AppButton type="submit" variant="primary" :loading="destinationsForm.processing">
                        {{ destinationsForm.processing ? 'Saving…' : 'Save destinations' }}
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </SettingsShell>
</template>
