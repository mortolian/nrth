<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{
    tax_rates: Array<{
        id: number;
        name: string;
        code: string;
        rate: number;
        rate_percent: number;
        is_default: boolean;
        is_exempt: boolean;
        is_active: boolean;
    }>;
}>();

const vatRateForm = useForm({
    name: '',
    code: '',
    rate_percent: 15,
    is_exempt: false,
    is_active: true,
    is_default: false,
});

const editingVatRateId = ref<number | null>(null);
const editingVatRateForm = useForm({
    name: '',
    code: '',
    rate_percent: 0,
    is_exempt: false,
    is_active: true,
    is_default: false,
});

const deletingVatRateId = ref<number | null>(null);
const deleteVatRateForm = useForm({});

const submitVatRate = () => {
    vatRateForm.post(route('tax.vat-rates.store'), {
        preserveScroll: true,
        onSuccess: () => {
            vatRateForm.reset();
            vatRateForm.name = '';
            vatRateForm.code = '';
            vatRateForm.rate_percent = 15;
            vatRateForm.is_exempt = false;
            vatRateForm.is_active = true;
            vatRateForm.is_default = false;
        },
    });
};

const beginEditVatRate = (rate: (typeof props.tax_rates)[number]) => {
    editingVatRateId.value = rate.id;
    editingVatRateForm.name = rate.name;
    editingVatRateForm.code = rate.code;
    editingVatRateForm.rate_percent = rate.rate_percent;
    editingVatRateForm.is_exempt = rate.is_exempt;
    editingVatRateForm.is_active = rate.is_active;
    editingVatRateForm.is_default = rate.is_default;
    editingVatRateForm.clearErrors();
};

const cancelEditVatRate = () => {
    editingVatRateId.value = null;
    editingVatRateForm.reset();
    editingVatRateForm.clearErrors();
};

const saveEditVatRate = () => {
    if (editingVatRateId.value === null) return;

    editingVatRateForm.put(route('tax.vat-rates.update', editingVatRateId.value), {
        preserveScroll: true,
        onSuccess: () => {
            cancelEditVatRate();
        },
    });
};

const removeVatRate = (rate: (typeof props.tax_rates)[number]) => {
    if (
        !window.confirm(
            `Delete VAT rate “${rate.name}” (${rate.code})? This cannot be undone if the rate is no longer referenced.`,
        )
    ) {
        return;
    }
    deletingVatRateId.value = rate.id;
    deleteVatRateForm.delete(route('tax.vat-rates.destroy', rate.id), {
        preserveScroll: true,
        onFinish: () => {
            deletingVatRateId.value = null;
        },
    });
};
</script>

<template>
    <AppLayout
        title="VAT rates"
        :breadcrumbs="[
            { label: 'Tax' },
            { label: 'VAT rates' },
        ]"
    >
        <PageHeader title="VAT rates" subtitle="Create and maintain VAT options used across invoices and expenses" />

        <AppCard class="mt-5">
            <div class="grid gap-3 md:grid-cols-6">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Name</label>
                    <AppInput v-model="vatRateForm.name" placeholder="e.g. VAT Standard" />
                    <p v-if="vatRateForm.errors.name" class="mt-1 text-xs text-rose-600">{{ vatRateForm.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Code</label>
                    <AppInput v-model="vatRateForm.code" placeholder="VAT15" />
                    <p v-if="vatRateForm.errors.code" class="mt-1 text-xs text-rose-600">{{ vatRateForm.errors.code }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Rate %</label>
                    <AppInput v-model="vatRateForm.rate_percent" type="number" min="0" max="100" step="0.01" :disabled="vatRateForm.is_exempt" />
                    <p v-if="vatRateForm.errors.rate_percent" class="mt-1 text-xs text-rose-600">{{ vatRateForm.errors.rate_percent }}</p>
                </div>
                <label class="mt-6 inline-flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="vatRateForm.is_exempt" type="checkbox" class="rounded border-slate-300">
                    Exempt
                </label>
                <div class="mt-5 flex items-end justify-end">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-md bg-brand-500 px-3 py-2 text-xs font-medium text-white transition hover:bg-brand-400 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="vatRateForm.processing"
                        @click="submitVatRate"
                    >
                        {{ vatRateForm.processing ? 'Adding…' : 'Add VAT rate' }}
                    </button>
                </div>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <div class="overflow-x-auto rounded-md border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Name</th>
                            <th class="px-3 py-2">Code</th>
                            <th class="px-3 py-2">Rate</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <tr v-if="!tax_rates.length">
                            <td colspan="5" class="px-3 py-3 text-xs text-slate-500">No VAT rates configured yet.</td>
                        </tr>
                        <tr v-for="rate in tax_rates" :key="rate.id">
                            <template v-if="editingVatRateId === rate.id">
                                <td class="px-3 py-2"><AppInput v-model="editingVatRateForm.name" /></td>
                                <td class="px-3 py-2"><AppInput v-model="editingVatRateForm.code" /></td>
                                <td class="px-3 py-2">
                                    <AppInput v-model="editingVatRateForm.rate_percent" type="number" min="0" max="100" step="0.01" :disabled="editingVatRateForm.is_exempt" />
                                </td>
                                <td class="px-3 py-2">
                                    <label class="inline-flex items-center gap-1 text-xs text-slate-600">
                                        <input v-model="editingVatRateForm.is_exempt" type="checkbox" class="rounded border-slate-300">
                                        Exempt
                                    </label>
                                    <label class="ml-2 inline-flex items-center gap-1 text-xs text-slate-600">
                                        <input v-model="editingVatRateForm.is_default" type="checkbox" class="rounded border-slate-300">
                                        Default
                                    </label>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button type="button" class="text-xs font-medium text-brand-700 hover:underline" :disabled="editingVatRateForm.processing" @click="saveEditVatRate">
                                            Save
                                        </button>
                                        <button type="button" class="text-xs text-slate-500 hover:underline" :disabled="editingVatRateForm.processing" @click="cancelEditVatRate">
                                            Cancel
                                        </button>
                                    </div>
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-3 py-2">
                                    <span class="font-medium text-slate-800">{{ rate.name }}</span>
                                    <span v-if="rate.is_default" class="ml-2 rounded bg-brand-50 px-1.5 py-0.5 text-[10px] font-semibold text-brand-700">Default</span>
                                </td>
                                <td class="px-3 py-2 text-slate-600">{{ rate.code }}</td>
                                <td class="px-3 py-2 text-slate-600">{{ rate.is_exempt ? 'Exempt' : `${rate.rate_percent.toFixed(2)}%` }}</td>
                                <td class="px-3 py-2 text-slate-600">{{ rate.is_active ? 'Active' : 'Inactive' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button type="button" class="text-xs font-medium text-brand-700 hover:underline" @click="beginEditVatRate(rate)">
                                            Edit
                                        </button>
                                        <button type="button" class="text-xs text-rose-600 hover:underline" :disabled="deleteVatRateForm.processing && deletingVatRateId === rate.id" @click="removeVatRate(rate)">
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="editingVatRateForm.hasErrors" class="mt-2 text-xs text-rose-600">
                Please fix the VAT rate form errors and try again.
            </p>
        </AppCard>
    </AppLayout>
</template>

