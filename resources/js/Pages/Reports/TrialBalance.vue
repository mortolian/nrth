<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import { useReportsTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const reportsTabs = useReportsTabs();

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
const page = usePage<{ vat_enabled?: boolean; business_currency?: string }>();
const bookCurrency = computed(() =>
    typeof page.props.business_currency === 'string' && page.props.business_currency.trim() !== ''
        ? page.props.business_currency
        : 'ZAR',
);
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, bookCurrency.value);
const vatEnabled = computed(() => Boolean(page.props.vat_enabled));

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
    <FeatureShell
        title="Reports"
        section="trial-balance"
        :tabs="reportsTabs"
        document-title="Trial Balance"
        subtitle="Standard accountant-ready debit and credit summary"
    >
        <template #actions>
            <AppButton variant="secondary">Export Excel</AppButton>
            <AppButton variant="secondary">Export PDF</AppButton>
        </template>

        <AppCard v-if="!vatEnabled" class="mt-5">
            <h3 class="text-lg font-semibold text-slate-900">Reports are unavailable</h3>
            <p class="mt-2 text-sm text-slate-600">
                VAT is disabled in Business settings, so report pages are hidden.
            </p>
            <a :href="route('settings.business', { tab: 'tax' })" class="mt-3 inline-block text-sm font-medium text-brand-700 hover:underline">
                Enable VAT in Business settings
            </a>
        </AppCard>
        <template v-else>
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
        </template>
    </FeatureShell>
</template>
