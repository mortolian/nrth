<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceInternalCurrencyApprox from '@/Components/InvoiceInternalCurrencyApprox.vue';
import RecordInvoicePaymentDrawer, {
    type RecordPaymentInvoiceInput,
} from '@/Components/RecordInvoicePaymentDrawer.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { CheckCircle2, CircleDot, Download, Edit3, Mail, Trash2, Wallet } from 'lucide-vue-next';

type Issuer = {
    name: string;
    address: string | null;
    email: string | null;
    phone: string | null;
    website: string | null;
    registration_number: string | null;
    vat_number: string | null;
};

type InvoicePayload = {
    id: number;
    number: string;
    status: string;
    reference: string | null;
    issue_date: string | null;
    due_date: string | null;
    notes: string | null;
    footer: string | null;
    subtotal_cents: number;
    vat_amount_cents: number;
    total_cents: number;
    amount_paid_cents: number;
    amount_due_cents: number;
    created_at: string | null;
    sent_at: string | null;
    viewed_at: string | null;
    paid_at: string | null;
    currency: string;
    company_currency_code?: string | null;
    fx_rate_invoice_to_company?: string | null;
    fx_rate_date?: string | null;
    total_company_currency_cents?: number | null;
    client: {
        id: number | null;
        name: string | null;
        email: string | null;
        phone: string | null;
    };
    line_items: Array<{
        id: number;
        description: string;
        quantity: number;
        unit_price_cents: number;
        vat_rate: number;
        vat_amount_cents: number;
        total_cents: number;
    }>;
    payments: Array<{
        id: number;
        amount_cents: number;
        payment_date: string | null;
        method: string;
        reference: string | null;
        notes: string | null;
    }>;
    activity_log: Array<{
        id: number;
        description: string;
        event: string | null;
        created_at: string | null;
    }>;
};

const props = defineProps<{
    issuer: Issuer;
    /** Company default currency (settings); for internal FX hint only. */
    company_currency: string;
    /** Mirrors company VAT settings: when false, VAT is not shown in totals. */
    charges_vat: boolean;
    invoice: InvoicePayload;
    can: {
        edit: boolean;
        send: boolean;
        mark_sent: boolean;
        void: boolean;
        unvoid: boolean;
        record_payment: boolean;
        delete: boolean;
    };
}>();

const bookCurrencySnapshot = computed(() => {
    const inv = props.invoice;
    if (
        inv.fx_rate_invoice_to_company == null
        || inv.fx_rate_date == null
        || inv.total_company_currency_cents == null
    ) {
        return null;
    }
    const r = Number(inv.fx_rate_invoice_to_company);
    if (!Number.isFinite(r) || r <= 0) {
        return null;
    }
    return {
        fx_rate: r,
        fx_rate_date: inv.fx_rate_date,
        total_company_currency_cents: inv.total_company_currency_cents,
    };
});

const paymentDrawerOpen = ref(false);

const recordPaymentInvoice = computed((): RecordPaymentInvoiceInput => ({
    id: props.invoice.id,
    number: props.invoice.number,
    client_name: props.invoice.client.name ?? undefined,
    amount_due_cents: props.invoice.amount_due_cents,
    total_cents: props.invoice.total_cents,
    currency: props.invoice.currency,
    company_currency_code: props.invoice.company_currency_code ?? null,
    fx_rate_invoice_to_company: props.invoice.fx_rate_invoice_to_company ?? null,
    fx_rate_date: props.invoice.fx_rate_date ?? null,
    total_company_currency_cents: props.invoice.total_company_currency_cents ?? null,
}));

const invoiceCurrency = computed(() => props.invoice.currency || 'ZAR');
const formatCents = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, invoiceCurrency.value);

const documentTitle = computed(() => (props.charges_vat ? 'Tax invoice' : 'Invoice'));

const issuerRegLine = computed(() => {
    const parts: string[] = [];
    if (props.issuer.registration_number) {
        parts.push(`Reg: ${props.issuer.registration_number}`);
    }
    if (props.issuer.vat_number) {
        parts.push(`VAT: ${props.issuer.vat_number}`);
    }
    return parts.length ? parts.join(' · ') : null;
});

const statusBadgeVariant = computed(() => {
    if (props.invoice.status === 'paid') return 'success';
    if (props.invoice.status === 'void') return 'neutral';
    if (props.invoice.status === 'overdue') return 'danger';
    return 'info';
});

const timeline = computed(() => ([
    { label: 'Created', at: props.invoice.created_at, done: Boolean(props.invoice.created_at) },
    { label: 'Sent', at: props.invoice.sent_at, done: Boolean(props.invoice.sent_at) },
    { label: 'Viewed', at: props.invoice.viewed_at, done: Boolean(props.invoice.viewed_at) },
    { label: 'Paid', at: props.invoice.paid_at, done: Boolean(props.invoice.paid_at) },
]));

const sendInvoice = () => router.post(route('invoicing.invoices.send', props.invoice.id));
const markAsSent = () => router.post(route('invoicing.invoices.mark-sent', props.invoice.id));
const voidInvoice = () => router.post(route('invoicing.invoices.void', props.invoice.id));
const unvoidInvoice = () => router.post(route('invoicing.invoices.unvoid', props.invoice.id));

const deleteInvoice = () => {
    if (!window.confirm('Permanently delete this invoice? This cannot be undone.')) {
        return;
    }
    router.delete(route('invoicing.invoices.destroy', props.invoice.id));
};

const downloadPdf = () => {
    window.location.assign(route('invoices.pdf.download', props.invoice.id));
};
const openRecordPayment = () => {
    paymentDrawerOpen.value = true;
};
</script>

<template>
    <AppLayout
        :title="invoice.number"
        :breadcrumbs="[
            { label: 'Invoicing' },
            { label: 'Invoices', href: route('invoicing.invoices.index') },
            { label: invoice.number },
        ]"
    >
        <header class="mb-6 min-w-0">
            <h1 class="truncate whitespace-nowrap text-2xl font-semibold text-slate-900">{{ invoice.number }}</h1>
            <p class="mt-1 truncate whitespace-nowrap text-base text-slate-500">
                {{ documentTitle }} · Issued {{ invoice.issue_date ?? '—' }}
            </p>
            <div class="mt-4 flex min-w-0 flex-nowrap justify-end gap-2 overflow-x-auto pb-1">
                <AppButton class="shrink-0" variant="primary" @click="downloadPdf">
                    <Download class="mr-1 h-4 w-4 shrink-0" /> Download PDF
                </AppButton>
                <AppButton
                    v-if="can.edit"
                    class="shrink-0"
                    variant="primary"
                    @click="router.visit(route('invoicing.invoices.edit', invoice.id))"
                >
                    <Edit3 class="mr-1 h-4 w-4 shrink-0" /> Edit
                </AppButton>
                <AppButton v-if="can.send" class="shrink-0" variant="primary" @click="sendInvoice">
                    <Mail class="mr-1 h-4 w-4 shrink-0" /> {{ invoice.status === 'draft' ? 'Send invoice' : 'Resend invoice' }}
                </AppButton>
                <AppButton v-if="can.mark_sent" class="shrink-0" variant="primary" @click="markAsSent">
                    <CheckCircle2 class="mr-1 h-4 w-4 shrink-0" /> Mark as sent
                </AppButton>
                <AppButton
                    v-if="['sent', 'partial', 'overdue'].includes(invoice.status) && can.record_payment"
                    class="shrink-0"
                    variant="primary"
                    @click="openRecordPayment"
                >
                    <Wallet class="mr-1 h-4 w-4 shrink-0" /> Record payment
                </AppButton>
                <AppButton v-if="['sent', 'partial'].includes(invoice.status)" class="shrink-0" variant="primary" type="button">
                    Send reminder
                </AppButton>
                <AppButton v-if="invoice.status === 'sent' && can.void" class="shrink-0" variant="primary" @click="voidInvoice">
                    Void
                </AppButton>
                <AppButton v-if="invoice.status === 'void' && can.unvoid" class="shrink-0" variant="primary" @click="unvoidInvoice">
                    Restore
                </AppButton>
                <AppButton
                    v-if="['paid', 'void'].includes(invoice.status)"
                    class="shrink-0"
                    variant="primary"
                    @click="router.visit(route('invoicing.invoices.create'))"
                >
                    Duplicate
                </AppButton>
                <AppButton v-if="can.delete" class="shrink-0" variant="primary" @click="deleteInvoice">
                    <Trash2 class="mr-1 h-4 w-4 shrink-0" /> Delete
                </AppButton>
            </div>
        </header>

        <div class="mt-5 grid gap-6 xl:grid-cols-3">
            <section class="xl:col-span-2">
                <AppCard class="space-y-5">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">From</p>
                            <p class="mt-1 text-sm font-medium text-slate-900">{{ issuer.name }}</p>
                            <p v-if="issuer.address" class="text-sm text-slate-600">{{ issuer.address }}</p>
                            <p v-if="issuer.email" class="text-sm text-slate-600">{{ issuer.email }}</p>
                            <p v-if="issuer.phone" class="text-sm text-slate-600">{{ issuer.phone }}</p>
                            <p v-if="issuer.website" class="text-sm text-slate-600">{{ issuer.website }}</p>
                            <p v-if="issuerRegLine" class="mt-0.5 text-xs text-slate-500">{{ issuerRegLine }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Bill To</p>
                            <p class="mt-1 text-sm font-medium text-slate-900">{{ invoice.client.name || 'Unknown client' }}</p>
                            <p class="text-sm text-slate-600">{{ invoice.client.email || '-' }}</p>
                            <p class="text-sm text-slate-600">{{ invoice.client.phone || '-' }}</p>
                        </div>
                    </div>

                    <div class="grid gap-2 text-sm md:grid-cols-3">
                        <div><span class="text-slate-500">Invoice #</span><p class="font-medium text-slate-900">{{ invoice.number }}</p></div>
                        <div><span class="text-slate-500">Issue Date</span><p class="font-medium text-slate-900">{{ invoice.issue_date || '-' }}</p></div>
                        <div><span class="text-slate-500">Due Date</span><p class="font-medium text-slate-900">{{ invoice.due_date || '-' }}</p></div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">Description</th>
                                    <th class="px-3 py-2 text-left">Qty</th>
                                    <th class="px-3 py-2 text-left">Unit Price</th>
                                    <th v-if="charges_vat" class="px-3 py-2 text-left">VAT</th>
                                    <th class="px-3 py-2 text-left">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="line in invoice.line_items" :key="line.id">
                                    <td class="px-3 py-2">{{ line.description }}</td>
                                    <td class="px-3 py-2">{{ line.quantity }}</td>
                                    <td class="px-3 py-2">{{ formatCents(line.unit_price_cents) }}</td>
                                    <td v-if="charges_vat" class="px-3 py-2">{{ formatCents(line.vat_amount_cents) }}</td>
                                    <td class="px-3 py-2 font-medium">{{ formatCents(line.total_cents) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="ml-auto w-full max-w-sm space-y-2 text-sm">
                        <div class="flex items-center justify-between"><span class="text-slate-500">Subtotal</span><span>{{ formatCents(invoice.subtotal_cents) }}</span></div>
                        <div v-if="charges_vat" class="flex items-center justify-between"><span class="text-slate-500">VAT</span><span>{{ formatCents(invoice.vat_amount_cents) }}</span></div>
                        <div class="flex items-center justify-between border-t border-slate-200 pt-2 font-semibold"><span>Total</span><span>{{ formatCents(invoice.total_cents) }}</span></div>
                    </div>

                    <InvoiceInternalCurrencyApprox
                        class="mt-3"
                        :invoice-currency="invoice.currency"
                        :company-currency="company_currency"
                        :total-cents="invoice.total_cents"
                        :amount-due-cents="invoice.amount_due_cents"
                        :book-snapshot="bookCurrencySnapshot"
                    />

                    <div v-if="invoice.notes" class="rounded-md border border-slate-200 p-3 text-sm text-slate-700">
                        <p class="mb-1 text-xs uppercase tracking-wide text-slate-500">Notes</p>
                        {{ invoice.notes }}
                    </div>
                    <div v-if="invoice.footer" class="rounded-md border border-slate-200 p-3 text-sm text-slate-700">
                        <p class="mb-1 text-xs uppercase tracking-wide text-slate-500">Footer</p>
                        {{ invoice.footer }}
                    </div>
                </AppCard>
            </section>

            <aside class="space-y-4">
                <AppCard>
                    <div class="flex items-center justify-between">
                        <h3 class="text-base font-semibold text-slate-900">Status</h3>
                        <AppBadge :variant="statusBadgeVariant">{{ invoice.status }}</AppBadge>
                    </div>
                    <div class="mt-4 space-y-2">
                        <div v-for="step in timeline" :key="step.label" class="flex items-start gap-2 text-sm">
                            <CircleDot :class="step.done ? 'text-brand-500' : 'text-slate-300'" class="mt-0.5 h-4 w-4" />
                            <div>
                                <p class="font-medium text-slate-800">{{ step.label }}</p>
                                <p class="text-xs text-slate-500">{{ step.at ? new Date(step.at).toLocaleString() : 'Pending' }}</p>
                            </div>
                        </div>
                    </div>
                </AppCard>

                <AppCard>
                    <h3 class="text-base font-semibold text-slate-900">Client details</h3>
                    <p class="mt-2 text-sm text-slate-700">{{ invoice.client.name || 'Unknown client' }}</p>
                    <p class="text-sm text-slate-600">{{ invoice.client.email || '-' }}</p>
                    <p class="text-sm text-slate-600">{{ invoice.client.phone || '-' }}</p>
                </AppCard>

                <AppCard>
                    <div class="flex items-center justify-between">
                        <h3 class="text-base font-semibold text-slate-900">Payment history</h3>
                        <AppButton v-if="can.record_payment" size="sm" variant="secondary" @click="openRecordPayment">Record Payment</AppButton>
                    </div>
                    <div v-if="invoice.payments.length" class="mt-3 space-y-2">
                        <div v-for="payment in invoice.payments" :key="payment.id" class="rounded-md border border-slate-200 p-2 text-sm">
                            <p class="font-medium text-slate-900">{{ formatCents(payment.amount_cents) }}</p>
                            <p class="text-xs text-slate-500">{{ payment.payment_date }} • {{ payment.method.toUpperCase() }}</p>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-slate-500">No payments recorded yet.</p>
                </AppCard>

                <AppCard>
                    <h3 class="text-base font-semibold text-slate-900">Activity log</h3>
                    <div v-if="invoice.activity_log.length" class="mt-3 space-y-2">
                        <div v-for="entry in invoice.activity_log" :key="entry.id" class="rounded-md border border-slate-200 p-2 text-sm">
                            <p class="text-slate-800">{{ entry.description }}</p>
                            <p class="text-xs text-slate-500">{{ entry.created_at ? new Date(entry.created_at).toLocaleString() : '-' }}</p>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-slate-500">No activity logged yet.</p>
                </AppCard>

            </aside>
        </div>

        <RecordInvoicePaymentDrawer
            :open="paymentDrawerOpen"
            :invoice="paymentDrawerOpen ? recordPaymentInvoice : null"
            :charges-vat="charges_vat"
            @update:open="paymentDrawerOpen = $event"
        />
    </AppLayout>
</template>
