<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';
import { LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { use } from 'echarts/core';
import VChart from 'vue-echarts';
use([LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

const props = defineProps({
    budgets: { type: Array, default: () => [] },
    /** Still sent for Inertia/tests; variance chart uses per-budget `monthly_variance`. */
    active_budget: { type: Object, default: null },
    company_currency: { type: String, default: 'ZAR' },
});

const expandedBudgetId = ref(null);

const formatCents = (cents, currency = 'ZAR') =>
    useFormatCurrency((Number(cents) || 0) / 100, currency || 'ZAR');

const deleteBudget = (budget) => {
    if (!window.confirm(`Delete budget “${budget.name}”? This cannot be undone.`)) return;
    router.delete(route('budgeting.destroy', budget.id), { preserveScroll: true });
};

const progressColor = (percentage) => {
    if (percentage >= 100) return 'bg-rose-500';
    if (percentage >= 80) return 'bg-amber-500';
    return 'bg-brand-500';
};

const toggleBudgetExpanded = (id) => {
    expandedBudgetId.value = expandedBudgetId.value === id ? null : id;
};

/** Sum a numeric field across line items in a category. */
function sumItemField(items, key) {
    return (items ?? []).reduce((sum, it) => sum + (Number(it[key]) || 0), 0);
}

/** Grand totals for the budget overview table (budget currency except envelope is already budget ccy). */
function budgetOverviewGrandTotals(budget) {
    const cats = budget.categories ?? [];
    let monthlyBudget = 0;
    let periodPlanned = 0;
    let annualised = 0;
    let envelope = 0;
    let linkedSpent = 0;
    let remainingLinked = 0;
    let hasLinked = false;
    for (const c of cats) {
        monthlyBudget += Number(c.monthly_planned_cents) || 0;
        periodPlanned += Number(c.period_planned_cents) || 0;
        envelope += Number(c.envelope_cents) || 0;
        if (c.has_account) {
            hasLinked = true;
            linkedSpent += Number(c.spent_cents) || 0;
            remainingLinked += Number(c.remaining_cents) || 0;
        }
        for (const it of c.items ?? []) {
            annualised += Number(it.annualized_budget_cents) || 0;
        }
    }
    return { monthlyBudget, periodPlanned, annualised, envelope, linkedSpent, remainingLinked, hasLinked };
}

function budgetVarianceChartOption(budget) {
    const rows = budget.monthly_variance ?? [];
    const sym = budget.currency === 'ZAR' ? 'R' : budget.currency;
    const series = [
        {
            name: 'Budgeted',
            type: 'line',
            data: rows.map((row) => row.budgeted),
            lineStyle: { type: 'dashed', color: '#0ea5e9' },
            itemStyle: { color: '#0ea5e9' },
        },
    ];
    if (budget.company_spend_aligned) {
        series.push({
            name: `Actual (${props.company_currency})`,
            type: 'line',
            data: rows.map((row) => row.actual ?? 0),
            lineStyle: { color: '#22c55e' },
            itemStyle: { color: '#22c55e' },
        });
    }
    return {
        tooltip: { trigger: 'axis' },
        legend: { data: series.map((s) => s.name) },
        grid: { left: 16, right: 16, top: 36, bottom: 24, containLabel: true },
        xAxis: { type: 'category', data: rows.map((row) => row.month) },
        yAxis: {
            type: 'value',
            axisLabel: {
                formatter: (value) => `${sym} ${(Number(value) / 100).toLocaleString('en-ZA')}`,
            },
        },
        series,
    };
}
</script>

<template>
    <AppLayout
        title="Budgeting"
        :breadcrumbs="[
            { label: 'Planning' },
            { label: 'Budgeting' },
        ]"
    >
        <PageHeader title="Budgeting">
            <template #actions>
                <AppButton variant="primary" @click="router.visit(route('budgeting.create'))">New Budget</AppButton>
            </template>
        </PageHeader>

        <AppCard v-if="!budgets.length" class="mt-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">No budgets yet</h3>
                    <p class="mt-1 text-sm text-slate-500">Create a budget to plan expense categories and track variance.</p>
                </div>
                <AppButton variant="primary" @click="router.visit(route('budgeting.create'))">New Budget</AppButton>
            </div>
        </AppCard>

        <AppCard v-else class="mt-5">
            <h3 class="mb-3 text-lg font-semibold text-slate-900">Budgets</h3>
            <AppTable
                :columns="[
                    { key: 'expand', label: '', widthClass: 'w-10 px-2' },
                    { key: 'name', label: 'Name' },
                    { key: 'period', label: 'Period' },
                    { key: 'allocated', label: 'Total allocated' },
                    { key: 'spent', label: 'Total spent' },
                    { key: 'used', label: '% used' },
                    { key: 'status', label: 'Status' },
                    { key: 'actions', label: '' },
                ]"
                :page="1"
                :last-page="1"
            >
                <template v-for="budget in budgets" :key="budget.id">
                    <tr class="align-middle">
                        <td class="w-px px-2 py-3">
                            <button
                                type="button"
                                class="inline-flex rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                                :aria-expanded="expandedBudgetId === budget.id"
                                :aria-label="expandedBudgetId === budget.id ? 'Collapse budget details' : 'Expand budget details'"
                                @click="toggleBudgetExpanded(budget.id)"
                            >
                                <ChevronDown
                                    v-if="expandedBudgetId === budget.id"
                                    class="h-4 w-4 shrink-0"
                                    stroke-width="2"
                                />
                                <ChevronRight v-else class="h-4 w-4 shrink-0" stroke-width="2" />
                            </button>
                        </td>
                        <td class="px-4 py-3 font-medium text-slate-900">{{ budget.name }}</td>
                        <td class="px-4 py-3">{{ budget.period }}</td>
                        <td class="px-4 py-3">{{ formatCents(budget.total_allocated, budget.currency) }}</td>
                        <td class="px-4 py-3">{{ formatCents(budget.total_spent, budget.currency) }}</td>
                        <td class="px-4 py-3">{{ budget.percentage_used }}%</td>
                        <td class="px-4 py-3">
                            <AppBadge :variant="budget.status === 'active' ? 'success' : 'neutral'">{{ budget.status }}</AppBadge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-1">
                                <AppButton size="sm" variant="ghost" @click="router.visit(route('budgeting.edit', budget.id))">
                                    Edit
                                </AppButton>
                                <AppButton
                                    size="sm"
                                    variant="ghost"
                                    class="text-rose-600 hover:text-rose-700"
                                    @click="deleteBudget(budget)"
                                >
                                    Delete
                                </AppButton>
                            </div>
                        </td>
                    </tr>
                    <tr v-show="expandedBudgetId === budget.id" class="bg-slate-50/80">
                        <td colspan="8" class="border-t border-slate-100 px-4 py-5">
                            <div class="flex flex-col gap-8 xl:flex-row xl:items-start">
                                <div class="shrink-0 xl:max-w-sm xl:pr-2">
                                    <h4 class="text-base font-semibold text-slate-900">{{ budget.name }}</h4>
                                    <p class="text-sm text-slate-500">{{ budget.period }}</p>
                                    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                                        <p class="text-sm text-slate-500">Overall spend vs budget</p>
                                        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ budget.percentage_used }}%</p>
                                        <p class="mt-1 text-sm text-slate-600">
                                            {{ formatCents(budget.total_spent, budget.currency) }} /
                                            {{ formatCents(budget.total_allocated, budget.currency) }}
                                        </p>
                                        <p v-if="!budget.company_spend_aligned" class="mt-1 text-xs text-amber-700">
                                            Spend totals mix ledger-linked categories only; set budget currency to
                                            {{ company_currency }} to compare all expenses.
                                        </p>
                                        <div class="mt-3 h-3 w-full rounded-full bg-slate-100">
                                            <div
                                                :class="progressColor(budget.percentage_used)"
                                                class="h-3 rounded-full"
                                                :style="{ width: `${Math.min(100, budget.percentage_used)}%` }"
                                            />
                                        </div>
                                    </div>
                                </div>

                                <div class="min-w-0 flex-1 space-y-3">
                                    <div>
                                        <h4 class="text-base font-semibold text-slate-900">Budget overview</h4>
                                        <p class="mt-1 text-xs text-slate-500">
                                            Known monthly expenses and category envelopes. Monetary columns use
                                            {{ budget.currency }} except “Monthly (line)”, which uses each line’s currency. Period
                                            totals assume
                                            {{ budget.months_in_period ?? '—' }} month(s) in range (× monthly in
                                            {{ budget.currency }}).
                                        </p>
                                    </div>
                                    <div
                                        class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm ring-1 ring-slate-900/5"
                                    >
                                        <table
                                            class="min-w-[52rem] w-full border-collapse text-left text-sm text-slate-800"
                                            :aria-label="`Budget overview for ${budget.name}`"
                                        >
                                            <thead>
                                                <tr class="border-b border-slate-200 bg-slate-100 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                                    <th class="sticky left-0 z-[1] border-r border-slate-200 bg-slate-100 px-3 py-2.5 whitespace-nowrap">
                                                        Category
                                                    </th>
                                                    <th class="px-3 py-2.5 whitespace-nowrap">Expense</th>
                                                    <th class="px-3 py-2.5 whitespace-nowrap">Line ccy</th>
                                                    <th class="px-3 py-2.5 text-right whitespace-nowrap">Monthly (line)</th>
                                                    <th class="px-3 py-2.5 text-right whitespace-nowrap">
                                                        Monthly ({{ budget.currency }})
                                                    </th>
                                                    <th class="px-3 py-2.5 text-right whitespace-nowrap">Period total</th>
                                                    <th class="px-3 py-2.5 text-right whitespace-nowrap">Annualised</th>
                                                    <th class="px-3 py-2.5 text-right whitespace-nowrap">Envelope</th>
                                                    <th class="px-3 py-2.5 text-right whitespace-nowrap">Linked spend</th>
                                                    <th class="px-3 py-2.5 text-right whitespace-nowrap">Remaining</th>
                                                </tr>
                                            </thead>
                                            <tbody
                                                v-for="(cat, catIdx) in budget.categories ?? []"
                                                :key="`cat-${catIdx}-${cat.name}`"
                                                class="border-b border-slate-100"
                                            >
                                                <tr
                                                    v-for="(it, idx) in cat.items?.length ? cat.items : [{}]"
                                                    :key="`cat-${catIdx}-line-${idx}`"
                                                    class="border-b border-slate-100 bg-white"
                                                >
                                                    <td
                                                        class="sticky left-0 z-[1] border-r border-slate-200 bg-white px-3 py-2 align-top font-medium text-slate-900 whitespace-nowrap"
                                                    >
                                                        {{ idx === 0 ? cat.name : '' }}
                                                    </td>
                                                    <td class="max-w-[14rem] px-3 py-2 align-top text-slate-800">
                                                        {{ it.label != null && it.label !== '' ? it.label : '—' }}
                                                    </td>
                                                    <td class="px-3 py-2 align-top tabular-nums text-slate-600 whitespace-nowrap">
                                                        {{ it.currency ?? '—' }}
                                                    </td>
                                                    <td class="px-3 py-2 text-right align-top tabular-nums whitespace-nowrap">
                                                        <template v-if="it.label != null && it.label !== ''">
                                                            {{ formatCents(it.monthly_amount_cents, it.currency) }}
                                                        </template>
                                                        <template v-else>—</template>
                                                    </td>
                                                    <td class="px-3 py-2 text-right align-top tabular-nums whitespace-nowrap">
                                                        <template v-if="it.label != null && it.label !== ''">
                                                            {{ formatCents(it.monthly_budget_currency_cents, budget.currency) }}
                                                        </template>
                                                        <template v-else>—</template>
                                                    </td>
                                                    <td class="px-3 py-2 text-right align-top tabular-nums whitespace-nowrap">
                                                        <template v-if="it.label != null && it.label !== ''">
                                                            {{ formatCents(it.period_total_budget_cents, budget.currency) }}
                                                        </template>
                                                        <template v-else>—</template>
                                                    </td>
                                                    <td class="px-3 py-2 text-right align-top tabular-nums whitespace-nowrap">
                                                        <template v-if="it.label != null && it.label !== ''">
                                                            {{ formatCents(it.annualized_budget_cents, budget.currency) }}
                                                        </template>
                                                        <template v-else>—</template>
                                                    </td>
                                                    <td class="px-3 py-2 text-right align-top text-slate-400 whitespace-nowrap">—</td>
                                                    <td class="px-3 py-2 text-right align-top text-slate-400 whitespace-nowrap">—</td>
                                                    <td class="px-3 py-2 text-right align-top text-slate-400 whitespace-nowrap">—</td>
                                                </tr>
                                                <tr class="border-b-2 border-slate-200 bg-slate-100/95 text-slate-900">
                                                    <td
                                                        class="sticky left-0 z-[1] border-r border-slate-200 bg-slate-100/95 px-3 py-2 font-semibold whitespace-nowrap"
                                                        colspan="4"
                                                    >
                                                        {{ cat.name }} — category total
                                                        <span
                                                            v-if="cat.planned_fill_percent > 100"
                                                            class="ml-2 font-normal text-rose-600"
                                                        >
                                                            (planned over envelope)
                                                        </span>
                                                    </td>
                                                    <td class="bg-slate-100/95 px-3 py-2 text-right font-semibold tabular-nums whitespace-nowrap">
                                                        {{ formatCents(cat.monthly_planned_cents, budget.currency) }}
                                                    </td>
                                                    <td class="bg-slate-100/95 px-3 py-2 text-right font-semibold tabular-nums whitespace-nowrap">
                                                        {{ formatCents(cat.period_planned_cents, budget.currency) }}
                                                    </td>
                                                    <td class="bg-slate-100/95 px-3 py-2 text-right font-semibold tabular-nums whitespace-nowrap">
                                                        {{
                                                            formatCents(
                                                                sumItemField(cat.items, 'annualized_budget_cents'),
                                                                budget.currency,
                                                            )
                                                        }}
                                                    </td>
                                                    <td class="bg-slate-100/95 px-3 py-2 text-right font-semibold tabular-nums whitespace-nowrap">
                                                        {{ formatCents(cat.envelope_cents, budget.currency) }}
                                                    </td>
                                                    <td class="bg-slate-100/95 px-3 py-2 text-right font-semibold tabular-nums whitespace-nowrap">
                                                        <template v-if="cat.has_account">
                                                            {{ formatCents(cat.spent_cents, budget.currency) }}
                                                        </template>
                                                        <template v-else>—</template>
                                                    </td>
                                                    <td class="bg-slate-100/95 px-3 py-2 text-right font-semibold tabular-nums whitespace-nowrap">
                                                        <template v-if="cat.has_account">
                                                            {{ formatCents(cat.remaining_cents, budget.currency) }}
                                                        </template>
                                                        <template v-else>—</template>
                                                    </td>
                                                </tr>
                                            </tbody>
                                            <tfoot>
                                                <tr class="border-t-2 border-slate-300 bg-brand-50/90 font-bold text-slate-900">
                                                    <td
                                                        class="sticky left-0 z-[1] border-r border-slate-200 bg-brand-50/90 px-3 py-2.5 whitespace-nowrap"
                                                        colspan="4"
                                                    >
                                                        All categories — grand total
                                                    </td>
                                                    <td class="bg-brand-50/90 px-3 py-2.5 text-right tabular-nums whitespace-nowrap">
                                                        {{ formatCents(budgetOverviewGrandTotals(budget).monthlyBudget, budget.currency) }}
                                                    </td>
                                                    <td class="bg-brand-50/90 px-3 py-2.5 text-right tabular-nums whitespace-nowrap">
                                                        {{ formatCents(budgetOverviewGrandTotals(budget).periodPlanned, budget.currency) }}
                                                    </td>
                                                    <td class="bg-brand-50/90 px-3 py-2.5 text-right tabular-nums whitespace-nowrap">
                                                        {{ formatCents(budgetOverviewGrandTotals(budget).annualised, budget.currency) }}
                                                    </td>
                                                    <td class="bg-brand-50/90 px-3 py-2.5 text-right tabular-nums whitespace-nowrap">
                                                        {{ formatCents(budgetOverviewGrandTotals(budget).envelope, budget.currency) }}
                                                    </td>
                                                    <td class="bg-brand-50/90 px-3 py-2.5 text-right tabular-nums whitespace-nowrap">
                                                        {{
                                                            budgetOverviewGrandTotals(budget).hasLinked
                                                                ? formatCents(
                                                                      budgetOverviewGrandTotals(budget).linkedSpent,
                                                                      budget.currency,
                                                                  )
                                                                : '—'
                                                        }}
                                                    </td>
                                                    <td class="bg-brand-50/90 px-3 py-2.5 text-right tabular-nums whitespace-nowrap">
                                                        {{
                                                            budgetOverviewGrandTotals(budget).hasLinked
                                                                ? formatCents(
                                                                      budgetOverviewGrandTotals(budget).remainingLinked,
                                                                      budget.currency,
                                                                  )
                                                                : '—'
                                                        }}
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-8 border-t border-slate-200 pt-6">
                                <h4 class="mb-3 text-base font-semibold text-slate-900">Monthly variance</h4>
                                <p v-if="!budget.company_spend_aligned" class="mb-3 text-sm text-amber-800">
                                    This budget is in {{ budget.currency }} but books use {{ company_currency }}; only the budgeted
                                    series is shown (actuals are in {{ company_currency }}).
                                </p>
                                <VChart class="h-72 w-full min-h-[18rem]" :option="budgetVarianceChartOption(budget)" autoresize />
                                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                                    <span
                                        v-for="row in (budget.monthly_variance ?? []).filter(
                                            (item) => item.variance != null && item.variance < 0,
                                        )"
                                        :key="`over-${budget.id}-${row.month}`"
                                        class="rounded-md bg-rose-50 px-2 py-1 text-rose-700"
                                    >
                                        Over budget: {{ row.month }} ({{ formatCents(Math.abs(row.variance), budget.currency) }})
                                    </span>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
            </AppTable>
        </AppCard>
    </AppLayout>
</template>
