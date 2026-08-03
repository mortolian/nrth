<script setup lang="ts">
import { watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type BackupRetention = {
    keep_daily: number;
    keep_weekly: number;
    keep_monthly: number;
    keep_yearly: number;
    weekly_on: string;
    delete_oldest_backups_when_using_more_megabytes_than: number | null;
};

const props = defineProps<{
    backup_retention: BackupRetention;
}>();

const retentionForm = useForm({
    keep_daily: props.backup_retention.keep_daily,
    keep_weekly: props.backup_retention.keep_weekly,
    keep_monthly: props.backup_retention.keep_monthly,
    keep_yearly: props.backup_retention.keep_yearly,
    weekly_on: props.backup_retention.weekly_on,
    delete_oldest_backups_when_using_more_megabytes_than:
        props.backup_retention.delete_oldest_backups_when_using_more_megabytes_than,
});

watch(
    () => props.backup_retention,
    (next) => {
        retentionForm.keep_daily = next.keep_daily;
        retentionForm.keep_weekly = next.keep_weekly;
        retentionForm.keep_monthly = next.keep_monthly;
        retentionForm.keep_yearly = next.keep_yearly;
        retentionForm.weekly_on = next.weekly_on;
        retentionForm.delete_oldest_backups_when_using_more_megabytes_than =
            next.delete_oldest_backups_when_using_more_megabytes_than;
        retentionForm.clearErrors();
    },
);

const weeklyOnOptions = [
    { label: 'Sunday', value: 'sunday' },
    { label: 'Monday', value: 'monday' },
    { label: 'Tuesday', value: 'tuesday' },
    { label: 'Wednesday', value: 'wednesday' },
    { label: 'Thursday', value: 'thursday' },
    { label: 'Friday', value: 'friday' },
    { label: 'Saturday', value: 'saturday' },
];

const saveRetention = () => {
    retentionForm
        .transform((data) => ({
            ...data,
            delete_oldest_backups_when_using_more_megabytes_than:
                data.delete_oldest_backups_when_using_more_megabytes_than === ''
                || data.delete_oldest_backups_when_using_more_megabytes_than === null
                    ? null
                    : Number(data.delete_oldest_backups_when_using_more_megabytes_than),
        }))
        .put(route('settings.instance.backup-retention.update'), {
            preserveScroll: true,
        });
};
</script>

<template>
    <AppLayout
        title="Backup retention"
        :breadcrumbs="[
            { label: 'Backups & exports', href: route('backups-exports.index', { section: 'backup' }) },
            { label: 'Backup retention' },
        ]"
    >
        <PageHeader
            title="Backup retention"
            subtitle="How many daily, weekly, monthly, and yearly backup zips to keep"
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
                One backup zip is created each day. On the weekly day it also counts as weekly;
                on month-end also monthly; on 31 Dec also yearly. Counts below are how many of
                each type to keep — older unprotected zips are rotated out at 03:30.
            </p>

            <form class="mt-4 space-y-4" @submit.prevent="saveRetention">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-500">Keep daily backups</label>
                        <AppInput
                            v-model="retentionForm.keep_daily"
                            type="number"
                            min="1"
                            max="90"
                            required
                        />
                        <p v-if="retentionForm.errors.keep_daily" class="mt-1 text-xs text-rose-600">
                            {{ retentionForm.errors.keep_daily }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-500">Keep weekly backups</label>
                        <AppInput
                            v-model="retentionForm.keep_weekly"
                            type="number"
                            min="0"
                            max="104"
                            required
                        />
                        <p v-if="retentionForm.errors.keep_weekly" class="mt-1 text-xs text-rose-600">
                            {{ retentionForm.errors.keep_weekly }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-500">Keep monthly backups</label>
                        <AppInput
                            v-model="retentionForm.keep_monthly"
                            type="number"
                            min="0"
                            max="60"
                            required
                        />
                        <p v-if="retentionForm.errors.keep_monthly" class="mt-1 text-xs text-rose-600">
                            {{ retentionForm.errors.keep_monthly }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-500">Keep yearly backups</label>
                        <AppInput
                            v-model="retentionForm.keep_yearly"
                            type="number"
                            min="0"
                            max="20"
                            required
                        />
                        <p v-if="retentionForm.errors.keep_yearly" class="mt-1 text-xs text-rose-600">
                            {{ retentionForm.errors.keep_yearly }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-500">Weekly backup day</label>
                        <AppSelect v-model="retentionForm.weekly_on" :options="weeklyOnOptions" />
                        <p v-if="retentionForm.errors.weekly_on" class="mt-1 text-xs text-rose-600">
                            {{ retentionForm.errors.weekly_on }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-slate-500">Max storage (MB)</label>
                        <AppInput
                            v-model="retentionForm.delete_oldest_backups_when_using_more_megabytes_than"
                            type="number"
                            min="100"
                            max="200000"
                            placeholder="Unlimited"
                        />
                        <p class="mt-1 text-xs text-slate-500">Leave blank for no size cap. Oldest unprotected backups are removed first.</p>
                        <p v-if="retentionForm.errors.delete_oldest_backups_when_using_more_megabytes_than" class="mt-1 text-xs text-rose-600">
                            {{ retentionForm.errors.delete_oldest_backups_when_using_more_megabytes_than }}
                        </p>
                    </div>
                </div>

                <FormActions class="!mt-2">
                    <AppButton type="submit" variant="primary" :loading="retentionForm.processing">
                        {{ retentionForm.processing ? 'Saving…' : 'Save retention' }}
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </AppLayout>
</template>
