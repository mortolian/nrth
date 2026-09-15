<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { useToast } from '@/Composables/useToast';

type GeneratedExpense = {
    id: number;
    date: string | null;
    description: string | null;
    status: string;
};

const props = defineProps<{
    recurring: {
        id: number;
        supplier_name: string;
        supplier_id: number | null;
        category: string;
        description: string | null;
        status: string;
        frequency: string;
        next_run_date: string | null;
        generated_count: number;
        total_cents: number;
        amount_excl_vat_cents: number;
        vat_amount_cents: number;
        generate_on_weekday: number | null;
        generate_on_day: number | null;
        generate_on_last_day: boolean;
        generate_on_month: number | null;
        limit_type: string;
        limit_count: number | null;
        limit_end_date: string | null;
        period_offset_months: number;
        notes: string | null;
        reference: string | null;
        vat_rate: string;
        paid_from_name: string | null;
        last_generated_at: string | null;
        expenses: GeneratedExpense[];
    };
    can: { manage: boolean; delete: boolean };
}>();

const toast = useToast();
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const weekdayNames: Record<number, string> = {
    1: 'Monday',
    2: 'Tuesday',
    3: 'Wednesday',
    4: 'Thursday',
    5: 'Friday',
    6: 'Saturday',
    7: 'Sunday',
};

const statusBadge = (status: string) => {
    if (status === 'active') return 'success';
    if (status === 'on_hold') return 'warning';
    if (status === 'completed') return 'neutral';
    return 'info';
};

const frequencyLabel = computed(() => {
    const f = props.recurring.frequency;
    return f.charAt(0).toUpperCase() + f.slice(1);
});

const statusLabel = computed(() => props.recurring.status.replaceAll('_', ' '));

const scheduleSummary = computed(() => {
    const r = props.recurring;
    if (r.frequency === 'weekly' && r.generate_on_weekday !== null) {
        return `Every ${weekdayNames[r.generate_on_weekday] ?? 'week'}`;
    }
    if (r.frequency === 'monthly') {
        if (r.generate_on_last_day) return 'Last day of each month';
        if (r.generate_on_day) return `Day ${r.generate_on_day} of each month`;
        return 'Monthly';
    }
    if (r.frequency === 'yearly') {
        const month = r.generate_on_month
            ? new Date(2000, r.generate_on_month - 1, 1).toLocaleString(undefined, { month: 'long' })
            : null;
        if (r.generate_on_last_day && month) return `Last day of ${month}`;
        if (month && r.generate_on_day) return `${month} ${r.generate_on_day}`;
        return 'Yearly';
    }
    return frequencyLabel.value;
});

const limitLabel = computed(() => {
    const r = props.recurring;
    if (r.limit_type === 'count') return `Stop after ${r.limit_count ?? '—'} expenses`;
    if (r.limit_type === 'end_date') return `Until ${r.limit_end_date ?? '—'}`;
    return 'No end date';
});

const periodOffsetLabel = computed(() => {
    const n = props.recurring.period_offset_months;
    if (n === 0) return 'Same month as expense date';
    if (n === -1) return 'Previous month';
    if (n === 1) return 'Next month';
    return `${n} months vs expense date`;
});

const vatRateLabel = computed(() => {
    const map: Record<string, string> = {
        vat15: 'VAT 15%',
        vat0: 'VAT 0%',
        exempt: 'Exempt',
        no_vat: 'No VAT',
    };
    return map[props.recurring.vat_rate] ?? props.recurring.vat_rate;
});

const post = (name: string, success: string) => {
    router.post(route(name, props.recurring.id), {}, {
        onSuccess: () => toast.success(success),
        preserveScroll: true,
    });
};

const destroy = () => {
    if (!confirm('Delete this recurring template? Generated expenses are kept.')) return;
    router.delete(route('expenses.recurring.destroy', props.recurring.id), {
        onSuccess: () => toast.success('Recurring expense deleted.'),
    });
};

const overflowActions = computed(() => {
    const actions: Array<{ id: string; label: string }> = [];
    if (props.can.manage) {
        if (props.recurring.status === 'active') {
            actions.push({ id: 'pause', label: 'Pause' });
        }
        if (props.recurring.status === 'on_hold') {
            actions.push({ id: 'resume', label: 'Resume' });
        }
        if (props.recurring.status !== 'completed') {
            actions.push({ id: 'complete', label: 'Mark completed' });
        }
    }
    if (props.can.delete) {
        actions.push({ id: 'delete', label: 'Delete' });
    }
    return actions;
});

const onOverflow = (actionId: string) => {
    if (actionId === 'pause') post('expenses.recurring.pause', 'Paused.');
    else if (actionId === 'resume') post('expenses.recurring.resume', 'Resumed.');
    else if (actionId === 'complete') post('expenses.recurring.complete', 'Marked completed.');
    else if (actionId === 'delete') destroy();
};
</script>

<template>
    <AppLayout
        :title="`Recurring · ${recurring.supplier_name}`"
        :breadcrumbs="[
            { label: 'Money Out', href: route('expenses.index') },
            { label: 'Recurring', href: route('expenses.recurring.index') },
            { label: recurring.supplier_name },
        ]"
    >
        <PageHeader :title="recurring.supplier_name" :subtitle="`${frequencyLabel} schedule`">
            <template #actions>
                <AppButton
                    v-if="can.manage"
                    variant="secondary"
                    @click="router.visit(route('expenses.recurring.edit', recurring.id))"
                >
                    Edit
                </AppButton>
                <AppButton
                    v-if="can.manage"
                    variant="primary"
                    @click="post('expenses.recurring.generate', 'Expense generated.')"
                >
                    Generate now
                </AppButton>
                <InvoiceRowActionsMenu
                    v-if="overflowActions.length"
                    :actions="overflowActions"
                    :aria-label="`More actions for ${recurring.supplier_name}`"
                    @select="onOverflow"
                />
            </template>
        </PageHeader>

        <div class="mt-5 space-y-6">
            <div class="flex flex-wrap items-center gap-2">
                <AppBadge :variant="statusBadge(recurring.status)" class="capitalize">
                    {{ statusLabel }}
                </AppBadge>
                <AppBadge variant="neutral">{{ vatRateLabel }}</AppBadge>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Next run</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">
                        {{ recurring.next_run_date || '—' }}
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Generated</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">
                        {{ recurring.generated_count }}
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Amount</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">
                        {{ formatCents(recurring.total_cents) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">Incl. VAT</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Last generated</p>
                    <p class="mt-1 text-sm font-medium text-slate-900">
                        {{
                            recurring.last_generated_at
                                ? new Date(recurring.last_generated_at).toLocaleString()
                                : 'Never'
                        }}
                    </p>
                </div>
            </div>

            <AppCard>
                <h3 class="text-base font-semibold text-slate-900">Schedule</h3>
                <div class="mt-5 grid gap-8 lg:grid-cols-3">
                    <div class="space-y-3 lg:border-r lg:border-slate-100 lg:pr-8">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cadence</p>
                        <dl class="space-y-2 text-sm">
                            <div>
                                <dt class="text-slate-500">Frequency</dt>
                                <dd class="font-medium text-slate-900">{{ scheduleSummary }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Next run</dt>
                                <dd class="tabular-nums text-slate-900">{{ recurring.next_run_date || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Limit</dt>
                                <dd class="text-slate-900">{{ limitLabel }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="space-y-3 lg:border-r lg:border-slate-100 lg:pr-8">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Template</p>
                        <dl class="space-y-2 text-sm">
                            <div>
                                <dt class="text-slate-500">Category</dt>
                                <dd class="font-medium text-slate-900">{{ recurring.category }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Paid from</dt>
                                <dd class="text-slate-900">{{ recurring.paid_from_name || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Period label offset</dt>
                                <dd class="text-slate-900">{{ periodOffsetLabel }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="space-y-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Amounts</p>
                        <dl class="space-y-2 text-sm">
                            <div>
                                <dt class="text-slate-500">Excl. VAT</dt>
                                <dd class="tabular-nums text-slate-900">{{ formatCents(recurring.amount_excl_vat_cents) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">VAT</dt>
                                <dd class="tabular-nums text-slate-900">{{ formatCents(recurring.vat_amount_cents) }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Reference</dt>
                                <dd class="text-slate-900">{{ recurring.reference || '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div v-if="recurring.description || recurring.notes" class="mt-8 space-y-4 border-t border-slate-100 pt-6">
                    <div v-if="recurring.description">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Description</p>
                        <p class="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-slate-700">{{ recurring.description }}</p>
                    </div>
                    <div v-if="recurring.notes">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Notes</p>
                        <p class="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-slate-700">{{ recurring.notes }}</p>
                    </div>
                </div>
            </AppCard>

            <AppCard class="overflow-hidden p-0">
                <div class="border-b border-slate-200 px-5 py-3">
                    <h3 class="text-base font-semibold text-slate-900">Generated expenses</h3>
                    <p class="mt-0.5 text-sm text-slate-500">History from this template</p>
                </div>
                <AppTable
                    embedded
                    :show-pagination="false"
                    table-class="text-sm"
                    :columns="[
                        { key: 'date', label: 'Date' },
                        { key: 'description', label: 'Description' },
                        { key: 'status', label: 'Status' },
                    ]"
                >
                    <tr v-if="!(recurring.expenses || []).length">
                        <td colspan="3" class="px-4 py-10">
                            <EmptyState
                                title="No expenses yet"
                                description="Generate now, or wait for the daily job on the next run date."
                            />
                        </td>
                    </tr>
                    <tr
                        v-for="expense in recurring.expenses || []"
                        :key="expense.id"
                        class="cursor-pointer border-b border-slate-100 hover:bg-slate-50"
                        @click="router.visit(route('expenses.edit', expense.id))"
                    >
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-700">
                            {{ expense.date || '—' }}
                        </td>
                        <td class="px-3 py-2 text-slate-900">
                            {{ expense.description || '—' }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <AppBadge class="capitalize" variant="neutral">
                                {{ expense.status }}
                            </AppBadge>
                        </td>
                    </tr>
                </AppTable>
            </AppCard>
        </div>
    </AppLayout>
</template>
