<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InstanceSettingsShell from '@/Components/InstanceSettingsShell.vue';

type TimezoneSettings = {
    timezone: string;
    env_timezone: string;
    summary: string;
};

const props = defineProps<{
    timezone: TimezoneSettings;
    timezone_options?: Array<{ value: string; label: string }>;
}>();

const form = useForm({
    timezone: props.timezone.timezone,
});

const timezoneOptions = computed(() =>
    Array.isArray(props.timezone_options) ? props.timezone_options : [],
);

watch(
    () => props.timezone,
    (next) => {
        form.timezone = next.timezone;
        form.clearErrors();
    },
);

const save = () => {
    form.put(route('settings.instance.timezone.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <InstanceSettingsShell
        section="timezone"
        title="Settings · Timezone"
        subtitle="Default timezone for this install. Businesses can override under Settings → Business."
    >
        <AppCard title="Default timezone">
            <p class="text-sm text-slate-600">
                Used for schedules, backups, and any business that has not set its own timezone.
                The sidebar clock follows the current business (or this default when none is set).
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Install env fallback (<code class="rounded bg-slate-100 px-1">APP_TIMEZONE</code>):
                {{ timezone.env_timezone }}
            </p>

            <form class="mt-5 space-y-4" @submit.prevent="save">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-500" for="instance-timezone">
                        Timezone
                    </label>
                    <AppSelect
                        id="instance-timezone"
                        v-model="form.timezone"
                        :options="timezoneOptions"
                    />
                    <p v-if="form.errors.timezone" class="mt-1 text-xs text-rose-600">
                        {{ form.errors.timezone }}
                    </p>
                </div>

                <FormActions bordered>
                    <AppButton
                        type="submit"
                        variant="primary"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Saving…' : 'Save timezone' }}
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </InstanceSettingsShell>
</template>
