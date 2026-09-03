<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type MappingField = {
    key: string;
    label: string;
    required: boolean;
};

const props = defineProps<{
    bankImport: {
        id: number;
        original_filename: string;
        account: { id: number; name: string; currency: string };
    };
    headers: string[];
    rows: string[][];
    delimiter: string;
    initialMapping?: Record<string, string>;
    mappingFields: MappingField[];
    batch?: { index: number; total: number } | null;
}>();

const headerOptions = computed(() =>
    props.headers.map((header) => ({ label: header, value: header }))
);

const mapping = ref<Record<string, string>>({
    transaction_date: props.initialMapping?.transaction_date ?? '',
    description: props.initialMapping?.description ?? '',
    amount: props.initialMapping?.amount ?? '',
    debit: props.initialMapping?.debit ?? '',
    credit: props.initialMapping?.credit ?? '',
    reference: props.initialMapping?.reference ?? '',
    value_date: props.initialMapping?.value_date ?? '',
    running_balance: props.initialMapping?.running_balance ?? '',
    date_format: props.initialMapping?.date_format ?? '',
});

const form = useForm({
    mapping: mapping.value,
    delimiter: props.delimiter,
});

const submit = () => {
    form.mapping = { ...mapping.value };
    form.post(route('banking.import.map.store', props.bankImport.id));
};

const pageTitle = computed(() => `Map columns — ${props.bankImport.original_filename}`);
</script>

<template>
    <AppLayout
        title="Map CSV columns"
        :breadcrumbs="[
            { label: 'Banking', href: route('banking.transactions.index') },
            { label: 'Import statement', href: route('banking.import.create') },
            { label: 'Map columns' },
        ]"
    >
        <PageHeader :title="pageTitle">
            <template #subtitle>
                <p class="text-sm text-slate-500">Account: {{ bankImport.account.name }} ({{ bankImport.account.currency }})</p>
                <p v-if="batch" class="mt-1 text-sm text-slate-500">
                    File {{ batch.index }} of {{ batch.total }}
                </p>
            </template>
        </PageHeader>

        <AppCard class="mt-5">
            <h2 class="text-sm font-semibold text-slate-900">Column mapping</h2>
            <p class="mt-1 text-xs text-slate-500">Map either a signed amount column, or separate debit and credit columns.</p>

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div v-for="field in mappingFields" :key="field.key">
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        {{ field.label }}
                        <span v-if="field.required" class="text-red-500">*</span>
                    </label>
                    <AppSelect
                        v-if="field.key !== 'date_format'"
                        :model-value="mapping[field.key]"
                        :options="[{ label: '—', value: '' }, ...headerOptions]"
                        @update:model-value="mapping[field.key] = $event"
                    />
                    <AppInput
                        v-else
                        v-model="mapping.date_format"
                        placeholder="e.g. d/m/Y (optional)"
                    />
                </div>
            </div>

            <p v-if="form.errors.mapping" class="mt-3 text-xs text-red-600">{{ form.errors.mapping }}</p>

            <FormActions>
                <AppButton variant="primary" :loading="form.processing" @click="submit">
                    {{ form.processing ? 'Working…' : batch && batch.total > 1 ? 'Preview and continue' : 'Preview import' }}
                </AppButton>
            </FormActions>
        </AppCard>

        <AppCard class="mt-5 overflow-x-auto">
            <h2 class="text-sm font-semibold text-slate-900">Preview (first rows)</h2>
            <table class="mt-3 min-w-full text-left text-sm">
                <thead>
                    <tr>
                        <th v-for="header in headers" :key="header" class="border-b border-slate-200 px-3 py-2 font-medium text-slate-600">
                            {{ header }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="index" class="border-b border-slate-100">
                        <td v-for="(cell, cellIndex) in row" :key="cellIndex" class="px-3 py-2 text-slate-700">
                            {{ cell }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </AppCard>
    </AppLayout>
</template>
