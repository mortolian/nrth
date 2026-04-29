<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

type Line = { account_id: number; code: string; name: string; amount: number };

const props = defineProps<{
    report: {
        assets: Line[];
        liabilities: Line[];
        equity: Line[];
        totals: { assets: number; liabilities: number; equity: number; liabilities_plus_equity: number };
        is_balanced: boolean;
    };
    as_of: string;
}>();

const asOf = ref(props.as_of);
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');
const page = usePage<{ vat_enabled?: boolean }>();
const vatEnabled = computed(() => Boolean(page.props.vat_enabled));

const apply = () => {
    router.get(route('reports.balance-sheet'), { as_of: asOf.value }, { preserveState: true, preserveScroll: true, replace: true });
};

const openStatement = (line: Line) => {
    router.visit(route('accounting.accounts.statement', line.account_id), { data: { to: asOf.value } });
};
</script>

<template>
    <AppLayout
        title="Balance Sheet"
        :breadcrumbs="[
            { label: 'Reports' },
            { label: 'Balance Sheet' },
        ]"
    >
        <PageHeader title="Balance Sheet" subtitle="Statement of financial position">
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
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">As of</label>
                    <AppInput v-model="asOf" type="date" />
                </div>
                <AppButton variant="secondary" @click="apply">Apply</AppButton>
            </div>
        </AppCard>

        <div class="mt-5 grid gap-5 xl:grid-cols-2">
            <AppCard>
                <h3 class="text-lg font-semibold text-slate-900">ASSETS</h3>
                <p class="mt-3 text-xs uppercase tracking-wide text-slate-500">Current Assets</p>
                <table class="mt-2 min-w-full text-sm">
                    <tbody>
                        <tr
                            v-for="line in report.assets"
                            :key="`asset-${line.account_id}`"
                            class="cursor-pointer border-b border-slate-100 hover:bg-slate-50"
                            @click="openStatement(line)"
                        >
                            <td class="px-2 py-2">{{ line.name }}</td>
                            <td class="px-2 py-2 text-right">{{ formatCents(line.amount) }}</td>
                        </tr>
                        <tr class="border-t border-slate-300 font-semibold">
                            <td class="px-2 py-2">Total Assets</td>
                            <td class="px-2 py-2 text-right">{{ formatCents(report.totals.assets) }}</td>
                        </tr>
                    </tbody>
                </table>
            </AppCard>

            <AppCard>
                <h3 class="text-lg font-semibold text-slate-900">LIABILITIES & EQUITY</h3>
                <p class="mt-3 text-xs uppercase tracking-wide text-slate-500">Current Liabilities</p>
                <table class="mt-2 min-w-full text-sm">
                    <tbody>
                        <tr
                            v-for="line in report.liabilities"
                            :key="`liability-${line.account_id}`"
                            class="cursor-pointer border-b border-slate-100 hover:bg-slate-50"
                            @click="openStatement(line)"
                        >
                            <td class="px-2 py-2">{{ line.name }}</td>
                            <td class="px-2 py-2 text-right">{{ formatCents(line.amount) }}</td>
                        </tr>
                        <tr class="border-t border-slate-300 font-semibold">
                            <td class="px-2 py-2">Total Liabilities</td>
                            <td class="px-2 py-2 text-right">{{ formatCents(report.totals.liabilities) }}</td>
                        </tr>
                    </tbody>
                </table>

                <p class="mt-4 text-xs uppercase tracking-wide text-slate-500">Equity</p>
                <table class="mt-2 min-w-full text-sm">
                    <tbody>
                        <tr
                            v-for="line in report.equity"
                            :key="`equity-${line.account_id}`"
                            class="cursor-pointer border-b border-slate-100 hover:bg-slate-50"
                            @click="openStatement(line)"
                        >
                            <td class="px-2 py-2">{{ line.name }}</td>
                            <td class="px-2 py-2 text-right">{{ formatCents(line.amount) }}</td>
                        </tr>
                        <tr class="border-t border-slate-300 font-semibold">
                            <td class="px-2 py-2">Total Equity</td>
                            <td class="px-2 py-2 text-right">{{ formatCents(report.totals.equity) }}</td>
                        </tr>
                        <tr class="border-t-2 border-slate-900 font-bold">
                            <td class="px-2 py-2">Total Liabilities + Equity</td>
                            <td class="px-2 py-2 text-right">{{ formatCents(report.totals.liabilities_plus_equity) }}</td>
                        </tr>
                    </tbody>
                </table>
            </AppCard>
        </div>

        <AppCard class="mt-5">
            <div
                :class="props.report.is_balanced ? 'bg-brand-50 text-brand-700' : 'bg-rose-50 text-rose-700'"
                class="rounded-md px-4 py-3 text-sm font-semibold"
            >
                {{ props.report.is_balanced ? 'Books are balanced ✓' : 'WARNING: Books are not balanced ✗' }}
            </div>
        </AppCard>
        </template>
    </AppLayout>
</template>
