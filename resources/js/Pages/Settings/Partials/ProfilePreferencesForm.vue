<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import AppSelect from '@/Components/AppSelect.vue';

const props = defineProps<{
    preferences: {
        notify_invoice_overdue: boolean;
        notify_vat_due: boolean;
        notify_provisional_tax: boolean;
        date_format: string;
        theme: string;
    };
}>();

const form = useForm({
    notify_invoice_overdue: props.preferences.notify_invoice_overdue,
    notify_vat_due: props.preferences.notify_vat_due,
    notify_provisional_tax: props.preferences.notify_provisional_tax,
    date_format: props.preferences.date_format,
    theme: props.preferences.theme,
});

const submit = () => {
    form.put(route('user-preferences.update'), { preserveScroll: true });
};
</script>

<template>
    <AppCard>
        <h3 class="text-base font-semibold text-slate-900">Notifications &amp; display</h3>
        <p class="mt-1 text-sm text-slate-500">Email reminders and how dates appear in the app.</p>

        <div class="mt-6 space-y-4">
            <label class="flex items-center gap-2 text-sm text-slate-800">
                <input v-model="form.notify_invoice_overdue" type="checkbox" class="rounded border-slate-300">
                Invoice overdue reminders
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-800">
                <input v-model="form.notify_vat_due" type="checkbox" class="rounded border-slate-300">
                VAT period due reminders
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-800">
                <input v-model="form.notify_provisional_tax" type="checkbox" class="rounded border-slate-300">
                Provisional tax due reminders
            </label>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-500">Default date format</label>
                <AppSelect
                    :model-value="form.date_format"
                    :options="[
                        { label: '2026-04-26 (ISO)', value: 'Y-m-d' },
                        { label: '26/04/2026', value: 'd/m/Y' },
                        { label: '26 Apr 2026', value: 'd M Y' },
                    ]"
                    @update:model-value="form.date_format = $event"
                />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-500">Theme</label>
                <AppSelect
                    :model-value="form.theme"
                    :options="[
                        { label: 'Light', value: 'light' },
                        { label: 'Dark', value: 'dark' },
                        { label: 'System', value: 'system' },
                    ]"
                    @update:model-value="form.theme = $event"
                />
                <p class="mt-1 text-xs text-slate-500">Theme preference is stored for future UI-wide dark mode.</p>
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <AppButton variant="primary" :disabled="form.processing" @click="submit">Save preferences</AppButton>
        </div>
    </AppCard>
</template>
