<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import FeatureShell from '@/Components/FeatureShell.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { useReportsTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const reportsTabs = useReportsTabs();

type IncomeAccount = {
    account_id: number;
    code: string;
    name: string;
    amount: number;
};

type JournalLine = {
    account_code: string;
    account_name: string;
    account_type: string | null;
    entry_type: 'debit' | 'credit';
    amount_cents: number;
    description: string | null;
    is_income: boolean;
};

type TaxLine = {
    type: string;
    type_label: string;
    rate_name: string;
    rate_percent: string | null;
    taxable_cents: number;
    tax_cents: number;
};

type IncomeTransaction = {
    id: number;
    date: string | null;
    reference: string | null;
    description: string | null;
    type: string;
    type_label: string;
    status: string;
    counterparty: string | null;
    recorded_by: string | null;
    income_cents: number;
    income_accounts: Array<{ code: string; name: string; amount: number }>;
    invoice: { number: string } | null;
    payment: {
        method_label: string | null;
        reference: string | null;
        notes: string | null;
        date: string | null;
    } | null;
    lines: JournalLine[];
    tax_lines: TaxLine[];
};

const props = defineProps<{
    report: {
        accounts: IncomeAccount[];
        totals: { income: number };
    };
    transactions: {
        data: IncomeTransaction[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    financial_year: {
        start_year: number;
        label: string;
        from: string;
        to: string;
        is_current: boolean;
    };
    year_options: Array<{ start_year: number; label: string; from: string; to: string }>;
    currency: string;
}>();

const page = usePage();
const canExport = computed(() => {
    const perms = page.props.team_permissions;
    return Array.isArray(perms) && perms.includes('reports.export');
});

const state = ref({ fy: props.financial_year.start_year });
watch(
    () => props.financial_year.start_year,
    (year) => {
        state.value.fy = year;
    },
);

const expanded = ref<number[]>([]);
watch(
    () => props.transactions.data.map((row) => row.id).join(','),
    () => {
        expanded.value = [];
    },
);

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, props.currency || 'ZAR');

const yearSelectOptions = computed(() =>
    props.year_options.map((year) => ({
        label: `FY ${year.label}`,
        value: String(year.start_year),
    })),
);

const pdfHref = computed(() =>
    route('reports.income.pdf', { fy: props.financial_year.start_year }),
);

const apply = (pageNumber = 1, perPage = props.transactions.per_page) => {
    router.get(
        route('reports.income'),
        { fy: state.value.fy, page: pageNumber, per_page: perPage },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const toggle = (id: number) => {
    expanded.value = expanded.value.includes(id)
        ? expanded.value.filter((open) => open !== id)
        : [...expanded.value, id];
};

const isOpen = (id: number) => expanded.value.includes(id);

const accountSummary = (row: IncomeTransaction) => {
    if (row.income_accounts.length === 0) {
        return '—';
    }

    const first = row.income_accounts[0];
    const label = `${first.code} ${first.name}`.trim();
    if (row.income_accounts.length === 1) {
        return label;
    }

    return `${label} +${row.income_accounts.length - 1}`;
};

const formatRate = (percent: string | null) => {
    if (percent === null || percent.trim() === '') {
        return '';
    }
    const value = Number(percent);
    if (Number.isNaN(value)) {
        return percent;
    }
    return `${value}%`;
};
</script>

<template>
    <FeatureShell
        title="Reports"
        section="income"
        :tabs="reportsTabs"
        document-title="Income report"
        subtitle="Recognised income for the financial year, with every posting behind it"
    >
        <template v-if="canExport" #actions>
            <AppButton as="a" variant="secondary" :href="pdfHref">Export PDF</AppButton>
        </template>

        <AppCard>
            <div class="grid gap-3 md:grid-cols-[minmax(0,16rem)_auto] md:items-end">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Financial year</label>
                    <AppSelect
                        :model-value="String(state.fy)"
                        :options="yearSelectOptions"
                        @update:model-value="state.fy = Number($event)"
                    />
                </div>
                <div>
                    <AppButton variant="secondary" @click="apply(1)">Apply</AppButton>
                </div>
            </div>
            <p class="mt-3 text-sm text-slate-500">
                {{ financial_year.from }} → {{ financial_year.to }}
                <span v-if="financial_year.is_current"> · current year</span>
            </p>
        </AppCard>

        <div class="mt-5 grid gap-4 md:grid-cols-3">
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Total income</p>
                <p
                    class="mt-2 text-3xl font-semibold tabular-nums"
                    :class="report.totals.income < 0 ? 'text-rose-700' : 'text-slate-900'"
                >
                    {{ formatCents(report.totals.income) }}
                </p>
                <p class="mt-1 text-xs text-slate-500">Net of income credits and debits</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Transactions</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-slate-900">{{ transactions.total }}</p>
                <p class="mt-1 text-xs text-slate-500">Posted entries that touch an income account</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Accounts</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-slate-900">{{ report.accounts.length }}</p>
                <p class="mt-1 text-xs text-slate-500">Income accounts with a balance this year</p>
            </AppCard>
        </div>

        <AppCard class="mt-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Income by account</h2>
            <div class="mt-3">
                <EmptyState
                    v-if="report.accounts.length === 0"
                    title="No income this year"
                    description="Posted credits to income accounts will appear here for the selected financial year."
                />
                <AppTable
                    v-else
                    embedded
                    dense
                    table-class="text-sm"
                    :show-pagination="false"
                    :columns="[
                        { key: 'code', label: 'Code' },
                        { key: 'name', label: 'Account' },
                        { key: 'amount', label: 'Amount', align: 'right' },
                    ]"
                >
                    <tr v-for="account in report.accounts" :key="account.account_id">
                        <td class="whitespace-nowrap px-3 py-2 font-medium text-slate-700">{{ account.code }}</td>
                        <td class="px-3 py-2 text-slate-900">{{ account.name }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-900">
                            {{ formatCents(account.amount) }}
                        </td>
                    </tr>
                    <template #footer>
                        <tr class="border-t border-slate-200 bg-slate-50 font-semibold">
                            <td class="px-3 py-2" colspan="2">Total income</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                                {{ formatCents(report.totals.income) }}
                            </td>
                        </tr>
                    </template>
                </AppTable>
            </div>
        </AppCard>

        <AppCard class="mt-5 overflow-hidden p-0">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Transactions</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Open a row for the journal, tax, and payment detail. The PDF includes every transaction in this financial year.
                </p>
            </div>

            <div v-if="transactions.total === 0" class="px-5 py-8">
                <EmptyState
                    title="No income transactions"
                    description="Nothing was posted to an income account in this financial year."
                />
            </div>
            <AppTable
                    v-else
                    embedded
                    dense
                    table-class="w-full table-fixed text-sm"
                    :page="transactions.current_page"
                    :last-page="transactions.last_page"
                    :per-page="transactions.per_page"
                    :total="transactions.total"
                    :columns="[
                        { key: 'expand', label: '', widthClass: 'w-[4%]' },
                        { key: 'date', label: 'Date', widthClass: 'w-[11%]' },
                        { key: 'reference', label: 'Reference', widthClass: 'w-[13%]' },
                        { key: 'description', label: 'Description', widthClass: 'w-[26%]' },
                        { key: 'counterparty', label: 'Counterparty', widthClass: 'w-[16%]' },
                        { key: 'account', label: 'Income account', widthClass: 'w-[18%]' },
                        { key: 'income', label: 'Income', align: 'right', widthClass: 'w-[12%]' },
                    ]"
                    @page-change="apply($event)"
                    @per-page-change="apply(1, $event)"
                >
                    <template v-for="row in transactions.data" :key="row.id">
                        <tr
                            class="cursor-pointer hover:bg-slate-50"
                            @click="toggle(row.id)"
                        >
                            <td class="px-3 py-2 text-slate-400">
                                <ChevronRight
                                    class="h-4 w-4 transition"
                                    :class="isOpen(row.id) ? 'rotate-90' : ''"
                                />
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ row.date }}</td>
                            <td class="truncate px-3 py-2 text-slate-700">{{ row.reference || '—' }}</td>
                            <td class="truncate px-3 py-2 text-slate-900">{{ row.description || row.type_label }}</td>
                            <td class="truncate px-3 py-2 text-slate-700">{{ row.counterparty || '—' }}</td>
                            <td class="truncate px-3 py-2 text-slate-700">{{ accountSummary(row) }}</td>
                            <td
                                class="whitespace-nowrap px-3 py-2 text-right font-medium tabular-nums"
                                :class="row.income_cents < 0 ? 'text-rose-700' : 'text-slate-900'"
                            >
                                {{ formatCents(row.income_cents) }}
                            </td>
                        </tr>
                        <tr v-if="isOpen(row.id)" class="bg-slate-50">
                            <td colspan="7" class="px-3 py-4">
                                <div class="grid gap-4 lg:grid-cols-[minmax(0,16rem)_1fr]">
                                    <dl class="space-y-2 text-sm">
                                        <div>
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">Type</dt>
                                            <dd class="text-slate-900">{{ row.type_label }}</dd>
                                        </div>
                                        <div v-if="row.invoice">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">Invoice</dt>
                                            <dd class="text-slate-900">{{ row.invoice.number }}</dd>
                                        </div>
                                        <div v-if="row.payment">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">Payment</dt>
                                            <dd class="text-slate-900">
                                                {{ row.payment.method_label || 'Payment' }}
                                                <span v-if="row.payment.date"> · {{ row.payment.date }}</span>
                                                <span v-if="row.payment.reference"> · {{ row.payment.reference }}</span>
                                            </dd>
                                            <dd v-if="row.payment.notes" class="text-slate-600">{{ row.payment.notes }}</dd>
                                        </div>
                                        <div v-if="row.recorded_by">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">Recorded by</dt>
                                            <dd class="text-slate-900">{{ row.recorded_by }}</dd>
                                        </div>
                                        <div v-if="row.description">
                                            <dt class="text-xs uppercase tracking-wide text-slate-500">Description</dt>
                                            <dd class="text-slate-900">{{ row.description }}</dd>
                                        </div>
                                    </dl>

                                    <div class="space-y-4">
                                        <table class="w-full table-fixed text-sm">
                                            <thead>
                                                <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                                                    <th class="w-[34%] py-1 pr-3 font-medium">Account</th>
                                                    <th class="w-[36%] py-1 pr-3 font-medium">Detail</th>
                                                    <th class="w-[15%] py-1 pr-3 text-right font-medium">Debit</th>
                                                    <th class="w-[15%] py-1 text-right font-medium">Credit</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="(line, index) in row.lines"
                                                    :key="`${row.id}-line-${index}`"
                                                    class="border-t border-slate-200"
                                                >
                                                    <td class="py-2 pr-3" :class="line.is_income ? 'font-medium text-slate-900' : 'text-slate-700'">
                                                        <span class="whitespace-nowrap">{{ line.account_code }}</span>
                                                        {{ line.account_name }}
                                                    </td>
                                                    <td class="py-2 pr-3 text-slate-600">{{ line.description || '—' }}</td>
                                                    <td class="whitespace-nowrap py-2 pr-3 text-right tabular-nums">
                                                        {{ line.entry_type === 'debit' ? formatCents(line.amount_cents) : '' }}
                                                    </td>
                                                    <td class="whitespace-nowrap py-2 text-right tabular-nums">
                                                        {{ line.entry_type === 'credit' ? formatCents(line.amount_cents) : '' }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>

                                        <table v-if="row.tax_lines.length" class="w-full table-fixed text-sm">
                                            <thead>
                                                <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                                                    <th class="w-[30%] py-1 pr-3 font-medium">Tax</th>
                                                    <th class="w-[40%] py-1 pr-3 font-medium">Rate</th>
                                                    <th class="w-[15%] py-1 pr-3 text-right font-medium">Taxable</th>
                                                    <th class="w-[15%] py-1 text-right font-medium">Tax</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="(tax, index) in row.tax_lines"
                                                    :key="`${row.id}-tax-${index}`"
                                                    class="border-t border-slate-200"
                                                >
                                                    <td class="py-2 pr-3 text-slate-900">{{ tax.type_label }}</td>
                                                    <td class="py-2 pr-3 text-slate-700">
                                                        {{ tax.rate_name }}
                                                        <span v-if="formatRate(tax.rate_percent)" class="text-slate-500">
                                                            {{ formatRate(tax.rate_percent) }}
                                                        </span>
                                                    </td>
                                                    <td class="whitespace-nowrap py-2 pr-3 text-right tabular-nums">
                                                        {{ formatCents(tax.taxable_cents) }}
                                                    </td>
                                                    <td class="whitespace-nowrap py-2 text-right tabular-nums">
                                                        {{ formatCents(tax.tax_cents) }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
            </AppTable>
        </AppCard>
    </FeatureShell>
</template>
