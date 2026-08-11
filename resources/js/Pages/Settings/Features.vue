<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';
import AppCard from '@/Components/AppCard.vue';
import AppButton from '@/Components/AppButton.vue';
import FormActions from '@/Components/FormActions.vue';

type ModuleRow = {
    name: string;
    label: string;
    description: string;
    default_enabled: boolean;
    enabled: boolean;
};

const props = defineProps<{
    modules: ModuleRow[];
}>();

const form = useForm({
    modules: props.modules.map((m) => ({
        name: m.name,
        enabled: m.enabled,
    })),
});

const moduleMeta = (name: string) => props.modules.find((m) => m.name === name);

const submit = () => {
    form.put(route('settings.features.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <SettingsShell
        section="features"
        title="Settings · Features"
        subtitle="Optional modules for this business. Disabling a module hides it without deleting data."
    >
        <form class="space-y-6" @submit.prevent="submit">
            <AppCard title="Optional features">
                <ul class="divide-y divide-slate-100">
                    <li
                        v-for="(row, index) in form.modules"
                        :key="row.name"
                        class="flex items-start gap-4 py-4 first:pt-0 last:pb-0"
                    >
                        <input
                            :id="`module-${row.name}`"
                            v-model="form.modules[index].enabled"
                            type="checkbox"
                            class="mt-1 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        <label :for="`module-${row.name}`" class="min-w-0 flex-1 cursor-pointer">
                            <span class="block text-sm font-medium text-slate-900">
                                {{ moduleMeta(row.name)?.label ?? row.name }}
                            </span>
                            <span class="mt-1 block text-sm text-slate-500">
                                {{ moduleMeta(row.name)?.description }}
                            </span>
                        </label>
                    </li>
                </ul>

                <p
                    v-if="form.modules.length === 0"
                    class="text-sm text-slate-500"
                >
                    No optional modules are available yet.
                </p>
            </AppCard>

            <FormActions bordered>
                <AppButton
                    type="submit"
                    variant="primary"
                    :disabled="form.processing"
                >
                    Save
                </AppButton>
            </FormActions>
        </form>
    </SettingsShell>
</template>
