<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { invoiceStatusBadgeVariant, invoiceStatusLabel } from '@/Composables/useInvoiceStatusBadge';
import { useToast } from '@/Composables/useToast';

type Line = {
    description?: string;
    quantity?: number | string;
    unit_price_cents?: number;
    vat_rate?: number | null;
};

type GeneratedInvoice = {
    id: number;
    number: string;
    issue_date: string | null;
    total_cents: number;
    status: string;
    currency: string;
};

const props = defineProps<{
    recurring: {
        id: number;
        client_name: string;
        client_id: number;
        status: string;
        frequency: string;
        next_run_date: string | null;
        generated_count: number;
        auto_send: boolean;
        currency: string;
        generate_on_weekday: number | null;
        generate_on_day: number | null;
        generate_on_last_day: boolean;
        generate_on_month: number | null;
        limit_type: string;
        limit_count: number | null;
        limit_end_date: string | null;
        period_offset_months: number;
        due_date_rule: string;
        due_days: number | null;
        due_on_day: number | null;
        reference: string | null;
        notes: string | null;
        footer: string | null;
        line_items: Line[];
        last_generated_at: string | null;
        invoices: GeneratedInvoice[];
    };
    can: { manage: boolean; delete: boolean };
}>();

const toast = useToast();
const formatCents = (cents: number, currency: string) =>
    useFormatCurrency((Number(cents) || 0) / 100, currency || 'ZAR');

const weekdayLabels = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

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
        return `Every ${weekdayLabels[r.generate_on_weekday] ?? 'week'}`;
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

const dueRuleLabel = computed(() => {
    const r = props.recurring;
    switch (r.due_date_rule) {
        case 'client_terms':
            return 'Client payment terms';
        case 'days_after_issue':
            return `${r.due_days ?? 0} days after issue`;
        case 'day_of_month':
            return `Day ${r.due_on_day ?? '—'} of issue month`;
        case 'day_of_next_month':
            return `Day ${r.due_on_day ?? '—'} of next month`;
        case 'last_day_of_month':
            return 'Last day of issue month';
        case 'last_day_of_next_month':
            return 'Last day of next month';
        default:
            return r.due_date_rule.replaceAll('_', ' ');
    }
});

const limitLabel = computed(() => {
    const r = props.recurring;
    if (r.limit_type === 'count') return `Stop after ${r.limit_count ?? '—'} invoices`;
    if (r.limit_type === 'end_date') return `Until ${r.limit_end_date ?? '—'}`;
    return 'No end date';
});

const periodOffsetLabel = computed(() => {
    const n = props.recurring.period_offset_months;
    if (n === 0) return 'Same month as issue date';
    if (n === 1) return 'Previous month (billing in arrears)';
    return `${n} months before issue`;
});

const templateTotalCents = computed(() =>
    (props.recurring.line_items || []).reduce((sum, line) => {
        const qty = Number(line.quantity) || 0;
        const unit = Number(line.unit_price_cents) || 0;
        return sum + Math.round(qty * unit);
    }, 0),
);

const post = (name: string, success: string) => {
    router.post(route(name, props.recurring.id), {}, {
        onSuccess: () => toast.success(success),
        preserveScroll: true,
    });
};

const destroy = () => {
    if (!confirm('Delete this recurring template? Generated invoices are kept.')) return;
    router.delete(route('invoicing.recurring.destroy', props.recurring.id), {
        onSuccess: () => toast.success('Recurring invoice deleted.'),
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
    if (actionId === 'pause') post('invoicing.recurring.pause', 'Paused.');
    else if (actionId === 'resume') post('invoicing.recurring.resume', 'Resumed.');
    else if (actionId === 'complete') post('invoicing.recurring.complete', 'Marked completed.');
    else if (actionId === 'delete') destroy();
};
</script>

<template>
    <AppLayout
        :title="`Recurring · ${recurring.client_name}`"
        :breadcrumbs="[
            { label: 'Money In', href: route('invoicing.invoices.index') },
            { label: 'Recurring', href: route('invoicing.recurring.index') },
            { label: recurring.client_name },
        ]"
    >
        <PageHeader :title="recurring.client_name" :subtitle="`${frequencyLabel} schedule`">
            <template #actions>
                <AppButton
                    v-if="can.manage"
                    variant="secondary"
                    @click="router.visit(route('invoicing.recurring.edit', recurring.id))"
                >
                    Edit
                </AppButton>
                <AppButton
                    v-if="can.manage"
                    variant="primary"
                    @click="post('invoicing.recurring.generate', 'Invoice generated.')"
                >
                    Generate now
                </AppButton>
                <InvoiceRowActionsMenu
                    v-if="overflowActions.length"
                    :actions="overflowActions"
                    :aria-label="`More actions for ${recurring.client_name}`"
                    @select="onOverflow"
                />
            </template>
        </PageHeader>

        <div class="mt-5 space-y-6">
            <div class="flex flex-wrap items-center gap-2">
                <AppBadge :variant="statusBadge(recurring.status)" class="capitalize">
                    {{ statusLabel }}
                </AppBadge>
                <AppBadge :variant="recurring.auto_send ? 'info' : 'neutral'">
                    {{ recurring.auto_send ? 'Auto-send on' : 'Manual send' }}
                </AppBadge>
                <AppBadge variant="neutral">{{ recurring.currency }}</AppBadge>
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
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Template total</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">
                        {{ formatCents(templateTotalCents, recurring.currency) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">Excl. VAT</p>
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
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Due dates</p>
                        <dl class="space-y-2 text-sm">
                            <div>
                                <dt class="text-slate-500">Rule</dt>
                                <dd class="font-medium text-slate-900">{{ dueRuleLabel }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Period label offset</dt>
                                <dd class="text-slate-900">{{ periodOffsetLabel }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="space-y-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Document</p>
                        <dl class="space-y-2 text-sm">
                            <div>
                                <dt class="text-slate-500">Reference</dt>
                                <dd class="text-slate-900">{{ recurring.reference || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Currency</dt>
                                <dd class="font-medium text-slate-900">{{ recurring.currency }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Client</dt>
                                <dd>
                                    <a
                                        :href="route('invoicing.clients.show', recurring.client_id)"
                                        class="font-medium text-brand-700 hover:underline"
                                        @click.prevent="router.visit(route('invoicing.clients.show', recurring.client_id))"
                                    >
                                        {{ recurring.client_name }}
                                    </a>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div v-if="recurring.notes || recurring.footer" class="mt-8 space-y-4 border-t border-slate-100 pt-6">
                    <div v-if="recurring.notes">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Notes</p>
                        <p class="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-slate-700">{{ recurring.notes }}</p>
                    </div>
                    <div v-if="recurring.footer">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Footer</p>
                        <p class="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-slate-700">{{ recurring.footer }}</p>
                    </div>
                </div>
            </AppCard>

            <AppCard class="overflow-hidden p-0">
                <div class="border-b border-slate-200 px-5 py-3">
                    <h3 class="text-base font-semibold text-slate-900">Template lines</h3>
                    <p class="mt-0.5 text-sm text-slate-500">Copied onto each generated invoice</p>
                </div>
                <AppTable
                    embedded
                    :show-pagination="false"
                    table-class="text-sm"
                    :columns="[
                        { key: 'description', label: 'Description' },
                        { key: 'qty', label: 'Qty' },
                        { key: 'price', label: 'Unit price' },
                        { key: 'vat', label: 'VAT' },
                        { key: 'amount', label: 'Amount' },
                    ]"
                >
                    <tr v-if="!(recurring.line_items || []).length">
                        <td colspan="5" class="px-3 py-8 text-center text-sm text-slate-500">No line items.</td>
                    </tr>
                    <tr
                        v-for="(line, i) in recurring.line_items || []"
                        :key="i"
                        class="border-b border-slate-100 text-slate-700"
                    >
                        <td class="px-3 py-2">{{ line.description || '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ line.quantity }}</td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">
                            {{ formatCents(Number(line.unit_price_cents) || 0, recurring.currency) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">
                            {{
                                line.vat_rate != null
                                    ? `${(Number(line.vat_rate) * 100).toFixed(0)}%`
                                    : '—'
                            }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums font-medium text-slate-900">
                            {{
                                formatCents(
                                    Math.round((Number(line.quantity) || 0) * (Number(line.unit_price_cents) || 0)),
                                    recurring.currency,
                                )
                            }}
                        </td>
                    </tr>
                </AppTable>
            </AppCard>

            <AppCard class="overflow-hidden p-0">
                <div class="border-b border-slate-200 px-5 py-3">
                    <h3 class="text-base font-semibold text-slate-900">Generated invoices</h3>
                    <p class="mt-0.5 text-sm text-slate-500">History from this template</p>
                </div>
                <AppTable
                    embedded
                    :show-pagination="false"
                    table-class="text-sm"
                    :columns="[
                        { key: 'number', label: 'Invoice' },
                        { key: 'issued', label: 'Issued' },
                        { key: 'amount', label: 'Amount' },
                        { key: 'status', label: 'Status' },
                    ]"
                >
                    <tr v-if="!(recurring.invoices || []).length">
                        <td colspan="4" class="px-4 py-10">
                            <EmptyState
                                title="No invoices yet"
                                description="Generate now, or wait for the daily job on the next run date."
                            />
                        </td>
                    </tr>
                    <tr
                        v-for="invoice in recurring.invoices || []"
                        :key="invoice.id"
                        class="cursor-pointer border-b border-slate-100 hover:bg-slate-50"
                        @click="router.visit(route('invoicing.invoices.show', invoice.id))"
                    >
                        <td class="whitespace-nowrap px-3 py-2 font-medium text-brand-700">
                            {{ invoice.number }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-700">
                            {{ invoice.issue_date || '—' }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-900">
                            {{ formatCents(invoice.total_cents, invoice.currency) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <AppBadge
                                class="capitalize"
                                :variant="invoiceStatusBadgeVariant(invoice.status)"
                            >
                                {{ invoiceStatusLabel(invoice.status) }}
                            </AppBadge>
                        </td>
                    </tr>
                </AppTable>
            </AppCard>
        </div>
    </AppLayout>
</template>
