<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppSelect from '@/Components/AppSelect.vue';
import { useToast } from '@/Composables/useToast';

const props = defineProps<{
    preferences: {
        notify_invoice_overdue: boolean;
        notify_vat_due: boolean;
        notify_license_disk: boolean;
        date_format: string;
        theme: string;
    };
}>();

const toast = useToast();

const form = useForm({
    notify_invoice_overdue: props.preferences.notify_invoice_overdue,
    notify_vat_due: props.preferences.notify_vat_due,
    notify_license_disk: props.preferences.notify_license_disk,
    date_format: props.preferences.date_format,
    theme: props.preferences.theme,
});

const submit = () => {
    form.put(route('user-preferences.update'), {
        preserveScroll: true,
        onError: () => {
            if (!form.hasErrors) {
                toast.error('Could not save preferences.');
            }
        },
    });
};
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
        <h4 class="text-sm font-semibold text-slate-900">Notifications &amp; display</h4>
        <p class="mt-0.5 text-xs text-slate-500">
            Email reminders for tax, invoicing, and vehicle deadlines, plus how dates and theme appear in the app.
        </p>

        <form class="mt-4" @submit.prevent="submit">
            <div class="max-w-2xl space-y-4">
                <div class="space-y-2.5 rounded-lg border border-slate-200/90 bg-white px-3 py-3">
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-800">
                        <input
                            v-model="form.notify_invoice_overdue"
                            type="checkbox"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        Invoice overdue reminders
                    </label>
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-800">
                        <input
                            v-model="form.notify_vat_due"
                            type="checkbox"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        VAT period due reminders
                    </label>
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-800">
                        <input
                            v-model="form.notify_license_disk"
                            type="checkbox"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        Vehicle licence disc expiry reminders
                    </label>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
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
                        <p class="mt-1 text-xs text-slate-500">Stored for future UI-wide dark mode.</p>
                    </div>
                </div>
            </div>

            <FormActions>
                <AppButton variant="primary" type="submit" :loading="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save preferences' }}
                </AppButton>
            </FormActions>
        </form>
    </section>
</template>
