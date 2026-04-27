<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const props = defineProps<{
    selected_year: number;
    available_years: number[];
    tax_year_summary: {
        income_statement_ready: boolean;
        trial_balance_ready: boolean;
        vat_summary_ready: boolean;
    };
    document_categories: Array<{
        key: string;
        label: string;
        count: number;
        total: number;
        warning: string | null;
    }>;
    checklist: {
        expenses_without_receipts: number;
        invoices_without_contracts: number;
        bank_statements_missing_months: number;
    };
}>();

const selectedYear = ref(String(props.selected_year));
const generating = ref(false);
const generationProgress = ref(0);

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const taxPackReadyCount = computed(() => {
    return [
        props.tax_year_summary.income_statement_ready,
        props.tax_year_summary.trial_balance_ready,
        props.tax_year_summary.vat_summary_ready,
    ].filter(Boolean).length;
});

const changeYear = () => {
    router.get(route('tax.documents.index'), { year: selectedYear.value }, { preserveState: true, preserveScroll: true, replace: true });
};

const generateTaxPack = () => {
    generating.value = true;
    generationProgress.value = 0;

    const timer = setInterval(() => {
        generationProgress.value += 20;
        if (generationProgress.value >= 100) {
            clearInterval(timer);
            generationProgress.value = 100;
            setTimeout(() => {
                generating.value = false;
                generationProgress.value = 0;
            }, 600);
        }
    }, 350);
};
</script>

<template>
    <AppLayout
        title="Tax Documents"
        :breadcrumbs="[
            { label: 'Tax' },
            { label: 'Documents' },
        ]"
    >
        <PageHeader title="Tax Documents Library" subtitle="Prepare tax-ready supporting packs for your accountant">
            <template #actions>
                <div class="flex items-center gap-2">
                    <AppSelect
                        :model-value="selectedYear"
                        :options="available_years.map((year) => ({ label: String(year), value: String(year) }))"
                        @update:model-value="selectedYear = $event; changeYear()"
                    />
                    <AppButton variant="primary" :disabled="generating" @click="generateTaxPack">
                        Generate Tax Pack
                    </AppButton>
                </div>
            </template>
        </PageHeader>

        <AppCard class="mt-5">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-900">Tax Year Summary</h3>
                <AppButton variant="secondary" :disabled="generating" @click="generateTaxPack">Generate PDF package</AppButton>
            </div>
            <div class="mt-3 grid gap-3 md:grid-cols-3">
                <div class="rounded-md border border-slate-200 p-3">
                    <p class="text-sm font-medium text-slate-900">Annual Income Statement</p>
                    <p class="mt-1 text-xs" :class="tax_year_summary.income_statement_ready ? 'text-brand-700' : 'text-slate-500'">
                        {{ tax_year_summary.income_statement_ready ? 'Ready' : 'Pending data' }}
                    </p>
                </div>
                <div class="rounded-md border border-slate-200 p-3">
                    <p class="text-sm font-medium text-slate-900">Full Year Trial Balance</p>
                    <p class="mt-1 text-xs" :class="tax_year_summary.trial_balance_ready ? 'text-brand-700' : 'text-slate-500'">
                        {{ tax_year_summary.trial_balance_ready ? 'Ready' : 'Pending setup' }}
                    </p>
                </div>
                <div class="rounded-md border border-slate-200 p-3">
                    <p class="text-sm font-medium text-slate-900">VAT Summary (all periods)</p>
                    <p class="mt-1 text-xs" :class="tax_year_summary.vat_summary_ready ? 'text-brand-700' : 'text-slate-500'">
                        {{ tax_year_summary.vat_summary_ready ? 'Ready' : 'Pending submissions' }}
                    </p>
                </div>
            </div>
            <p class="mt-3 text-xs text-slate-500">Ready sections: {{ taxPackReadyCount }}/3</p>
            <div v-if="generating" class="mt-3">
                <div class="h-2 w-full rounded-full bg-slate-100">
                    <div class="h-2 rounded-full bg-brand-500 transition-all" :style="{ width: `${generationProgress}%` }" />
                </div>
                <p class="mt-1 text-xs text-slate-500">Generating tax pack... {{ generationProgress }}%</p>
            </div>
        </AppCard>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <AppCard v-for="category in document_categories" :key="category.key">
                <h3 class="text-base font-semibold text-slate-900">{{ category.label }}</h3>
                <p class="mt-2 text-sm text-slate-600">Count: {{ category.count }}</p>
                <p class="text-sm text-slate-600">Total value: {{ formatCents(category.total) }}</p>
                <p v-if="category.warning" class="mt-2 text-xs text-amber-700">{{ category.warning }}</p>
            </AppCard>
        </div>

        <AppCard class="mt-5">
            <h3 class="mb-3 text-lg font-semibold text-slate-900">Missing Documents Checklist</h3>
            <div class="space-y-2 text-sm">
                <div class="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2">
                    <span>Expenses without receipts</span>
                    <span :class="checklist.expenses_without_receipts > 0 ? 'font-semibold text-rose-600' : 'text-brand-700'">
                        {{ checklist.expenses_without_receipts }}
                    </span>
                </div>
                <div class="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2">
                    <span>Invoices without signed contracts</span>
                    <span :class="checklist.invoices_without_contracts > 0 ? 'font-semibold text-rose-600' : 'text-brand-700'">
                        {{ checklist.invoices_without_contracts }}
                    </span>
                </div>
                <div class="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2">
                    <span>Missing bank statement months</span>
                    <span :class="checklist.bank_statements_missing_months > 0 ? 'font-semibold text-rose-600' : 'text-brand-700'">
                        {{ checklist.bank_statements_missing_months }}
                    </span>
                </div>
            </div>
        </AppCard>
    </AppLayout>
</template>
