<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

type TrialLine = {
    account_id: number;
    code: string;
    name: string;
    debit: number;
    credit: number;
};

type GroupKey = 'asset' | 'liability' | 'equity' | 'income' | 'expense';

const props = defineProps<{
    report: {
        groups: Record<GroupKey, TrialLine[]>;
        subtotals: Record<string, { debit: number; credit: number }>;
        totals: { debits: number; credits: number; difference: number; is_balanced: boolean };
    };
    as_of: string;
}>();

const asOf = ref(props.as_of);
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const groupMeta: Array<{ key: GroupKey; label: string }> = [
    { key: 'asset', label: 'Assets' },
    { key: 'liability', label: 'Liabilities' },
    { key: 'equity', label: 'Equity' },
    { key: 'income', label: 'Income' },
    { key: 'expense', label: 'Expenses' },
];

const apply = () => {
    router.get(route('reports.trial-balance'), { as_of: asOf.value }, { preserveState: true, preserveScroll: true, replace: true });
};
</script>

<template>
    <AppLayout
        title="Trial Balance"
        :breadcrumbs="[
            { label: 'Reports' },
            { label: 'Trial Balance' },
        ]"
    >
        <PageHeader title="Trial Balance" subtitle="Standard accountant-ready debit and credit summary">
            <template #actions>
                <AppButton variant="secondary">Export Excel</AppButton>
                <AppButton variant="secondary">Export PDF</AppButton>
            </template>
        </PageHeader>

        <AppCard class="mt-5">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">As of</label>
                    <AppInput v-model="asOf" type="date" />
                </div>
                <AppButton variant="secondary" @click="apply">Apply</AppButton>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <table class="min-w-full text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Account Code</th>
                        <th class="px-3 py-2 text-left">Account Name</th>
                        <th class="px-3 py-2 text-right">Debit</th>
                        <th class="px-3 py-2 text-right">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="group in groupMeta" :key="group.key">
                        <tr class="bg-slate-50">
                            <td class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600" colspan="4">
                                {{ group.label }}
                            </td>
                        </tr>
                        <tr v-for="line in report.groups[group.key]" :key="`${group.key}-${line.account_id}`" class="border-b border-slate-100">
                            <td class="px-3 py-2 font-mono text-xs text-slate-600">{{ line.code }}</td>
                            <td class="px-3 py-2">{{ line.name }}</td>
                            <td class="px-3 py-2 text-right">{{ line.debit ? formatCents(line.debit) : '—' }}</td>
                            <td class="px-3 py-2 text-right">{{ line.credit ? formatCents(line.credit) : '—' }}</td>
                        </tr>
                        <tr class="border-b border-slate-200 bg-slate-50 font-semibold">
                            <td class="px-3 py-2" colspan="2">{{ group.label }} subtotal</td>
                            <td class="px-3 py-2 text-right">{{ formatCents(report.subtotals[group.key]?.debit ?? 0) }}</td>
                            <td class="px-3 py-2 text-right">{{ formatCents(report.subtotals[group.key]?.credit ?? 0) }}</td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-900 text-base font-bold">
                        <td class="px-3 py-3" colspan="2">Totals</td>
                        <td class="px-3 py-3 text-right">{{ formatCents(report.totals.debits) }}</td>
                        <td class="px-3 py-3 text-right">{{ formatCents(report.totals.credits) }}</td>
                    </tr>
                </tfoot>
            </table>
        </AppCard>

        <AppCard class="mt-5">
            <div
                :class="report.totals.is_balanced ? 'bg-brand-50 text-brand-700' : 'bg-rose-50 text-rose-700'"
                class="rounded-md px-4 py-4 text-xl font-bold"
            >
                <span v-if="report.totals.is_balanced">✓ Balanced</span>
                <span v-else>✗ Unbalanced — difference: {{ formatCents(report.totals.difference) }}</span>
            </div>
        </AppCard>
    </AppLayout>
</template>
