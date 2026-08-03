<script setup lang="ts">
import { watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

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
    (next) => {
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
        });
};

const testPath = () => {
    testPathForm
        .transform(() => ({
            root: destinationsForm.path.root || undefined,
        }))
        .post(route('settings.instance.backup-destinations.test-path'), {
            preserveScroll: true,
        });
};
</script>

<template>
    <AppLayout
        title="Offsite destinations"
        :breadcrumbs="[
            { label: 'Backups & exports', href: route('backups-exports.index', { section: 'backup' }) },
            { label: 'Offsite destinations' },
        ]"
    >
        <PageHeader
            title="Offsite destinations"
            subtitle="Mirror each local backup zip to S3-compatible storage or a path/NFS mount"
        >
            <template #actions>
                <Link
                    :href="route('backups-exports.index', { section: 'backup' })"
                    class="text-sm font-medium text-brand-700 hover:underline"
                >
                    Back to instance backup
                </Link>
            </template>
        </PageHeader>

        <AppCard class="mt-5">
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
                            <p v-if="destinationsForm.errors['s3.bucket']" class="mt-1 text-xs text-rose-600">{{ destinationsForm.errors['s3.bucket'] }}</p>
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
                        <p v-if="destinationsForm.errors['path.root']" class="mt-1 text-xs text-rose-600">{{ destinationsForm.errors['path.root'] }}</p>
                    </div>
                    <div class="mt-3">
                        <AppButton type="button" variant="secondary" size="sm" :loading="testPathForm.processing" @click="testPath">
                            {{ testPathForm.processing ? 'Testing…' : 'Test path' }}
                        </AppButton>
                    </div>
                </div>

                <FormActions class="!mt-2">
                    <AppButton type="submit" variant="primary" :loading="destinationsForm.processing">
                        {{ destinationsForm.processing ? 'Saving…' : 'Save destinations' }}
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </AppLayout>
</template>
