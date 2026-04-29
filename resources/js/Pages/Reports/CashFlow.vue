<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type OperatingLine = {
    key: string;
    label: string;
    amount: number | null;
    is_heading?: boolean;
};

type FlowLine = { label: string; amount: number; is_placeholder?: boolean };

const props = defineProps<{
    report: {
        operating: { lines: OperatingLine[]; subtotal: number };
        investing: { lines: FlowLine[]; subtotal: number };
        financing: { lines: FlowLine[]; subtotal: number };
        summary: {
            net_change: number;
            opening_cash: number;
            closing_cash: number;
            reconciliation_difference: number;
        };
    };
    period: { from: string; to: string; preset: string };
}>();

const state = ref({
    preset: props.period.preset || 'this_month',
    from: props.period.from,
    to: props.period.to,
});

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');
const page = usePage<{ vat_enabled?: boolean }>();
const vatEnabled = computed(() => Boolean(page.props.vat_enabled));

const formatSignedFlow = (cents: number) => {
    const n = Number(cents) || 0;
    if (n === 0) {
        return formatCents(0);
    }
    if (n < 0) {
        return `(${formatCents(Math.abs(n))})`;
    }
    return formatCents(n);
};

const apply = () => {
    router.get(route('reports.cash-flow'), { ...state.value }, { preserveState: true, preserveScroll: true, replace: true });
};

const reconVisible = Math.abs(props.report.summary.reconciliation_difference) > 1;
</script>

<template>
    <AppLayout
        title="Cash Flow"
        :breadcrumbs="[
            { label: 'Reports' },
            { label: 'Cash Flow' },
        ]"
    >
        <PageHeader title="Cash Flow Statement" subtitle="Indirect method — operating, investing, and financing activities">
            <template #actions>
                <AppButton variant="secondary">Export PDF</AppButton>
                <AppButton variant="secondary">Export Excel</AppButton>
            </template>
        </PageHeader>

        <AppCard v-if="!vatEnabled" class="mt-5">
            <h3 class="text-lg font-semibold text-slate-900">Reports are unavailable</h3>
            <p class="mt-2 text-sm text-slate-600">
                VAT is disabled in Company settings, so report pages are hidden.
            </p>
            <a :href="route('settings.company', { tab: 'tax' })" class="mt-3 inline-block text-sm font-medium text-brand-700 hover:underline">
                Enable VAT in Company settings
            </a>
        </AppCard>
        <template v-else>
        <AppCard class="mt-5">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Period</label>
                    <AppSelect
                        :model-value="state.preset"
                        :options="[
                            { label: 'This Month', value: 'this_month' },
                            { label: 'Last Month', value: 'last_month' },
                            { label: 'This Quarter', value: 'this_quarter' },
                            { label: 'This Tax Year', value: 'this_tax_year' },
                            { label: 'Last Tax Year', value: 'last_tax_year' },
                            { label: 'Custom', value: 'custom' },
                        ]"
                        @update:model-value="state.preset = $event"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                    <AppInput v-model="state.from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                    <AppInput v-model="state.to" type="date" />
                </div>
                <div class="flex items-end">
                    <AppButton variant="secondary" @click="apply">Apply</AppButton>
                </div>
            </div>
        </AppCard>

        <AppCard class="mt-5 space-y-8">
            <section>
                <h3 class="text-lg font-semibold text-slate-900">Operating Activities</h3>
                <p class="mt-1 text-sm text-slate-500">Cash from business operations</p>
                <table class="mt-3 min-w-full text-sm">
                    <tbody>
                        <tr v-for="line in report.operating.lines" :key="line.key" class="border-b border-slate-100">
                            <td
                                class="px-2 py-2"
                                :class="line.is_heading ? 'pt-4 text-xs font-semibold uppercase tracking-wide text-slate-600' : 'pl-4'"
                            >
                                {{ line.label }}
                            </td>
                            <td class="px-2 py-2 text-right font-medium text-slate-900">
                                <template v-if="line.is_heading" />
                                <template v-else>{{ formatSignedFlow(line.amount ?? 0) }}</template>
                            </td>
                        </tr>
                        <tr class="border-t border-slate-300 font-semibold">
                            <td class="px-2 py-2">Net Cash from Operating Activities</td>
                            <td class="px-2 py-2 text-right">{{ formatSignedFlow(report.operating.subtotal) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section>
                <h3 class="text-lg font-semibold text-slate-900">Investing Activities</h3>
                <table class="mt-3 min-w-full text-sm">
                    <tbody>
                        <tr
                            v-for="(line, idx) in report.investing.lines"
                            :key="`inv-${idx}`"
                            class="border-b border-slate-100"
                            :class="line.is_placeholder ? 'text-slate-400 italic' : ''"
                        >
                            <td class="px-2 py-2 pl-4">{{ line.label }}</td>
                            <td class="px-2 py-2 text-right font-medium">
                                {{ line.is_placeholder ? '—' : formatSignedFlow(line.amount) }}
                            </td>
                        </tr>
                        <tr class="border-t border-slate-300 font-semibold">
                            <td class="px-2 py-2">Net Cash from Investing Activities</td>
                            <td class="px-2 py-2 text-right">{{ formatSignedFlow(report.investing.subtotal) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section>
                <h3 class="text-lg font-semibold text-slate-900">Financing Activities</h3>
                <p class="mt-1 text-sm text-slate-500">Owner contributions and drawings</p>
                <table class="mt-3 min-w-full text-sm">
                    <tbody>
                        <tr
                            v-for="(line, idx) in report.financing.lines"
                            :key="`fin-${idx}`"
                            class="border-b border-slate-100"
                            :class="line.is_placeholder ? 'text-slate-400 italic' : ''"
                        >
                            <td class="px-2 py-2 pl-4">{{ line.label }}</td>
                            <td class="px-2 py-2 text-right font-medium">
                                {{ line.is_placeholder ? '—' : formatSignedFlow(line.amount) }}
                            </td>
                        </tr>
                        <tr class="border-t border-slate-300 font-semibold">
                            <td class="px-2 py-2">Net Cash from Financing Activities</td>
                            <td class="px-2 py-2 text-right">{{ formatSignedFlow(report.financing.subtotal) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <div class="border-t-2 border-slate-900 pt-4">
                <table class="min-w-full text-sm">
                    <tbody>
                        <tr class="font-medium">
                            <td class="px-2 py-2">Net Change in Cash</td>
                            <td class="px-2 py-2 text-right">{{ formatSignedFlow(report.summary.net_change) }}</td>
                        </tr>
                        <tr class="border-t border-slate-200">
                            <td class="px-2 py-2">Opening Cash Balance</td>
                            <td class="px-2 py-2 text-right">{{ formatCents(report.summary.opening_cash) }}</td>
                        </tr>
                        <tr class="border-t-2 border-slate-900 text-base font-bold">
                            <td class="px-2 py-3">Closing Cash Balance</td>
                            <td class="px-2 py-3 text-right">{{ formatCents(report.summary.closing_cash) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-slate-500">Closing balance is the sum of bank and petty cash accounts (chart codes 1010 / 1020 and accounts under the 1000 group).</p>
            </div>

            <div
                v-if="reconVisible"
                class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                Note: Operating + investing + financing does not exactly reconcile to the change in cash by
                {{ formatCents(Math.abs(report.summary.reconciliation_difference)) }}.
                This can happen when transactions mix operating and non-operating accounts on the same entry.
            </div>
        </AppCard>
        </template>
    </AppLayout>
</template>
