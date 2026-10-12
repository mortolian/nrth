<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import { useToast } from '@/Composables/useToast';

const props = defineProps<{
    preferences: {
        notify_invoice_overdue: boolean;
        notify_vat_due: boolean;
        notify_license_disk: boolean;
        notify_recurring_invoice: boolean;
        notify_recurring_expense: boolean;
        date_format: string;
        theme: string;
        sticky_filters_enabled: boolean;
        sticky_filters_minutes: number;
    };
}>();

const toast = useToast();

const form = useForm({
    notify_invoice_overdue: props.preferences.notify_invoice_overdue,
    notify_vat_due: props.preferences.notify_vat_due,
    notify_license_disk: props.preferences.notify_license_disk,
    notify_recurring_invoice: props.preferences.notify_recurring_invoice,
    notify_recurring_expense: props.preferences.notify_recurring_expense,
    date_format: props.preferences.date_format,
    theme: props.preferences.theme,
    sticky_filters_enabled: props.preferences.sticky_filters_enabled,
    sticky_filters_minutes: props.preferences.sticky_filters_minutes,
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
            Email reminders for tax, invoicing, expenses, and vehicle deadlines, plus how dates and theme appear in the app.
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
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-800">
                        <input
                            v-model="form.notify_recurring_invoice"
                            type="checkbox"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        Recurring invoice created
                    </label>
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-800">
                        <input
                            v-model="form.notify_recurring_expense"
                            type="checkbox"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        Recurring expense created
                    </label>
                </div>

                <div class="space-y-3 rounded-lg border border-slate-200/90 bg-white px-3 py-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900">Searches and filters</p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Keep a list’s search and filters when you open a record and come back. They clear after the time below, or when you use Clear.
                        </p>
                    </div>
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-800">
                        <input
                            v-model="form.sticky_filters_enabled"
                            type="checkbox"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        Remember searches and filters
                    </label>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="text-sm text-slate-800" for="sticky-filters-minutes">Forget them after</label>
                        <AppInput
                            id="sticky-filters-minutes"
                            v-model.number="form.sticky_filters_minutes"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            max="1440"
                            class="w-24"
                            :disabled="!form.sticky_filters_enabled"
                        />
                        <span class="text-sm text-slate-600">minutes</span>
                    </div>
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
