<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceInternalCurrencyApprox from '@/Components/InvoiceInternalCurrencyApprox.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import MarkdownProse from '@/Components/MarkdownProse.vue';
import PageHeader from '@/Components/PageHeader.vue';
import RecordInvoicePaymentDrawer, {
    type RecordPaymentInvoiceInput,
} from '@/Components/RecordInvoicePaymentDrawer.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { invoiceStatusBadgeVariant, invoiceStatusLabel } from '@/Composables/useInvoiceStatusBadge';
import { useToast } from '@/Composables/useToast';
import { CircleDot, Download, Edit3, Eye, Mail, QrCode, Wallet, X } from 'lucide-vue-next';

type Issuer = {
    name: string;
    address: string | null;
    address_lines?: string[];
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
    notes_html?: string | null;
    footer_html?: string | null;
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
    business_currency_code?: string | null;
    fx_rate_invoice_to_business?: string | null;
    fx_rate_date?: string | null;
    total_business_currency_cents?: number | null;
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
        bank_amount_business_cents: number | null;
        payment_date: string | null;
        method: string;
        reference: string | null;
        notes: string | null;
        can_undo: boolean;
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
    /** Business default currency (settings); for internal FX hint only. */
    business_currency: string;
    /** Mirrors company VAT settings: when false, VAT is not shown in totals. */
    charges_vat: boolean;
    invoice: InvoicePayload;
    can: {
        edit: boolean;
        clone: boolean;
        send: boolean;
        remind: boolean;
        mark_sent: boolean;
        void: boolean;
        unvoid: boolean;
        record_payment: boolean;
        delete: boolean;
    };
    /** Enabled checkout providers for this invoice (e.g. stripe, payfast). */
    online_payment_providers: string[];
    /** Absolute URL to the customer-facing pay page (after link is created). */
    public_pay_url: string | null;
    /** Same-origin URL to a PNG QR code for `public_pay_url` (requires an active link). */
    public_pay_qr_url: string | null;
    /** Whether the team can create or rotate the public link (issued, unpaid states). */
    can_manage_public_pay_link: boolean;
}>();

const bookCurrencySnapshot = computed(() => {
    const inv = props.invoice;
    if (
        inv.fx_rate_invoice_to_business == null
        || inv.fx_rate_date == null
        || inv.total_business_currency_cents == null
    ) {
        return null;
    }
    const r = Number(inv.fx_rate_invoice_to_business);
    if (!Number.isFinite(r) || r <= 0) {
        return null;
    }
    return {
        fx_rate: r,
        fx_rate_date: inv.fx_rate_date,
        total_business_currency_cents: inv.total_business_currency_cents,
    };
});

const paymentDrawerOpen = ref(false);
const pdfPreviewOpen = ref(false);
const receiptPreviewOpen = ref(false);
const receiptPreviewPaymentId = ref<number | null>(null);
const sendingReceiptId = ref<number | null>(null);
const toast = useToast();
const sendingInvoice = ref(false);
const sendingReminder = ref(false);
const markingSent = ref(false);

const recordPaymentInvoice = computed((): RecordPaymentInvoiceInput => ({
    id: props.invoice.id,
    number: props.invoice.number,
    client_name: props.invoice.client.name ?? undefined,
    amount_due_cents: props.invoice.amount_due_cents,
    total_cents: props.invoice.total_cents,
    currency: props.invoice.currency,
    business_currency_code: props.invoice.business_currency_code ?? null,
    fx_rate_invoice_to_business: props.invoice.fx_rate_invoice_to_business ?? null,
    fx_rate_date: props.invoice.fx_rate_date ?? null,
    total_business_currency_cents: props.invoice.total_business_currency_cents ?? null,
}));

const invoiceCurrency = computed(() => props.invoice.currency || 'ZAR');
/** Guard: `.includes` on a non-array throws and white-screens the page. */
const onlinePaymentProviders = computed(() =>
    Array.isArray(props.online_payment_providers) ? props.online_payment_providers : [],
);
const formatCents = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, invoiceCurrency.value);
const isForeignCurrencyInvoice = computed(
    () =>
        Boolean(props.invoice.currency)
        && Boolean(props.business_currency)
        && props.invoice.currency !== props.business_currency,
);
const formatBusinessCents = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, props.business_currency);
const paymentBankAmountCents = (payment: InvoicePayload['payments'][number]) =>
    payment.bank_amount_business_cents;

const documentTitle = computed(() => (props.charges_vat ? 'Tax invoice' : 'Invoice'));

const clientDisplayName = computed(() => props.invoice.client.name?.trim() || 'Unknown client');

const headerSubtitle = computed(
    () => `${clientDisplayName.value} · ${documentTitle.value} · Issued ${props.invoice.issue_date ?? '—'}`,
);

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

const issuerAddressLines = computed(() => {
    const lines = props.issuer.address_lines?.map((line) => line.trim()).filter(Boolean) ?? [];
    if (lines.length) {
        return lines;
    }
    const legacy = props.issuer.address?.trim();
    if (!legacy) {
        return [];
    }
    return legacy.split(/\n+/).map((line) => line.trim()).filter(Boolean);
});

const statusBadgeVariant = computed(() =>
    invoiceStatusBadgeVariant(props.invoice.status, {
        isOverdue: props.invoice.status === 'overdue',
    }),
);

const statusLabel = computed(() => invoiceStatusLabel(props.invoice.status));

const timeline = computed(() => ([
    { label: 'Created', at: props.invoice.created_at, done: Boolean(props.invoice.created_at) },
    { label: 'Sent', at: props.invoice.sent_at, done: Boolean(props.invoice.sent_at) },
    { label: 'Paid', at: props.invoice.paid_at, done: Boolean(props.invoice.paid_at) },
]));

const ACTIVITY_PREVIEW_COUNT = 5;
const activityLogExpanded = ref(false);
const visibleActivityLog = computed(() => {
    const entries = props.invoice.activity_log ?? [];
    if (activityLogExpanded.value || entries.length <= ACTIVITY_PREVIEW_COUNT) {
        return entries;
    }
    return entries.slice(0, ACTIVITY_PREVIEW_COUNT);
});
const hiddenActivityCount = computed(() =>
    Math.max(0, (props.invoice.activity_log?.length ?? 0) - ACTIVITY_PREVIEW_COUNT),
);

const firstErrorMessage = (errors: Record<string, string>) => {
    const first = Object.values(errors)[0];
    return first || 'Something went wrong.';
};

const sendInvoice = () => {
    if (sendingInvoice.value) return;
    router.post(route('invoicing.invoices.send', props.invoice.id), {}, {
        preserveScroll: true,
        onStart: () => {
            sendingInvoice.value = true;
        },
        onError: (errors) => {
            toast.error(firstErrorMessage(errors));
        },
        onFinish: () => {
            sendingInvoice.value = false;
        },
    });
};

const sendReminder = () => {
    if (sendingReminder.value) return;
    router.post(route('invoicing.invoices.remind', props.invoice.id), {}, {
        preserveScroll: true,
        onStart: () => {
            sendingReminder.value = true;
        },
        onError: (errors) => {
            toast.error(firstErrorMessage(errors));
        },
        onFinish: () => {
            sendingReminder.value = false;
        },
    });
};

const markAsSent = () => {
    if (markingSent.value) return;
    router.post(route('invoicing.invoices.mark-sent', props.invoice.id), {}, {
        preserveScroll: true,
        onStart: () => {
            markingSent.value = true;
        },
        onError: (errors) => {
            toast.error(firstErrorMessage(errors));
        },
        onFinish: () => {
            markingSent.value = false;
        },
    });
};
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

const openPdfPreview = () => {
    pdfPreviewOpen.value = true;
};

const openPdfInNewTab = () => {
    window.open(pdfPreviewUrl.value, '_blank', 'noopener,noreferrer');
};

const pdfPreviewUrl = computed(() => route('invoices.pdf.preview', props.invoice.id));

const openRecordPayment = () => {
    paymentDrawerOpen.value = true;
};

const startOnlinePayment = (provider: string) => {
    router.post(
        route('invoicing.invoices.online-payments.store', props.invoice.id),
        { provider },
        { preserveScroll: true },
    );
};

const showPublicPayCard = computed(
    () =>
        props.invoice.status !== 'draft'
        && props.invoice.status !== 'void'
        && (props.public_pay_url !== null || props.can_manage_public_pay_link),
);

const createPublicPayLink = () => {
    router.post(route('invoicing.invoices.public-pay-link.store', props.invoice.id), {}, { preserveScroll: true });
};

const regeneratePublicPayLink = () => {
    if (
        !window.confirm(
            'Generate a new link? The old QR code and URL will stop working (use this if a link was shared by mistake).',
        )
    ) {
        return;
    }
    router.post(
        route('invoicing.invoices.public-pay-link.store', props.invoice.id),
        { regenerate: true },
        { preserveScroll: true },
    );
};

const copyPublicPayUrl = async () => {
    if (!props.public_pay_url) {
        return;
    }
    try {
        await navigator.clipboard.writeText(props.public_pay_url);
    } catch {
        window.prompt('Copy this link:', props.public_pay_url);
    }
};

const openPublicPayPage = () => {
    if (props.public_pay_url) {
        window.open(props.public_pay_url, '_blank', 'noopener,noreferrer');
    }
};

const undoPayment = (paymentId: number) => {
    if (
        !window.confirm(
            'Undo this payment? The ledger entry will be reversed and the invoice balance will be updated. This is for mistaken entries.',
        )
    ) {
        return;
    }
    router.post(
        route('invoicing.invoices.payments.undo', [props.invoice.id, paymentId]),
        {},
        { preserveScroll: true },
    );
};

const receiptPreviewUrl = computed(() => {
    if (receiptPreviewPaymentId.value == null) {
        return '';
    }
    return route('invoicing.invoices.payments.receipt.preview', [props.invoice.id, receiptPreviewPaymentId.value]);
});

const openReceiptPreview = (paymentId: number) => {
    receiptPreviewPaymentId.value = paymentId;
    receiptPreviewOpen.value = true;
};

const downloadReceipt = (paymentId: number) => {
    window.location.assign(route('invoicing.invoices.payments.receipt.download', [props.invoice.id, paymentId]));
};

const openReceiptInNewTab = () => {
    if (receiptPreviewPaymentId.value == null) {
        return;
    }
    window.open(receiptPreviewUrl.value, '_blank', 'noopener,noreferrer');
};

const sendReceipt = (paymentId: number) => {
    if (sendingReceiptId.value != null) {
        return;
    }
    if (!props.invoice.client.email) {
        toast.error('Add an email address on the client before sending this receipt.');
        return;
    }
    router.post(
        route('invoicing.invoices.payments.receipt.send', [props.invoice.id, paymentId]),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                sendingReceiptId.value = paymentId;
            },
            onError: (errors) => {
                toast.error(firstErrorMessage(errors));
            },
            onFinish: () => {
                sendingReceiptId.value = null;
            },
        },
    );
};

const canRecordPayment = computed(
    () => ['sent', 'partial', 'overdue'].includes(props.invoice.status) && props.can.record_payment,
);

const canPayOnline = computed(
    () => canRecordPayment.value && props.invoice.amount_due_cents > 0,
);

/** Secondary / destructive actions — kept in a compact ⋮ menu on small screens. */
const overflowActions = computed(() => {
    const actions: Array<{ id: string; label: string }> = [];

    if (props.can.mark_sent) {
        actions.push({ id: 'mark_sent', label: 'Mark as sent' });
    }
    if (canPayOnline.value && onlinePaymentProviders.value.includes('stripe')) {
        actions.push({ id: 'pay_stripe', label: 'Pay with Stripe' });
    }
    if (canPayOnline.value && onlinePaymentProviders.value.includes('payfast')) {
        actions.push({ id: 'pay_payfast', label: 'Pay with PayFast' });
    }
    if (props.can.remind) {
        actions.push({ id: 'remind', label: 'Send reminder' });
    }
    if (props.invoice.status === 'sent' && props.can.void) {
        actions.push({ id: 'void', label: 'Void' });
    }
    if (props.invoice.status === 'void' && props.can.unvoid) {
        actions.push({ id: 'unvoid', label: 'Restore' });
    }
    if (props.can.clone) {
        actions.push({ id: 'clone', label: 'Clone' });
    }
    if (props.can.delete) {
        actions.push({ id: 'delete', label: 'Delete' });
    }

    return actions;
});

const onOverflowAction = (actionId: string) => {
    if (actionId === 'mark_sent') {
        markAsSent();
    } else if (actionId === 'pay_stripe') {
        startOnlinePayment('stripe');
    } else if (actionId === 'pay_payfast') {
        startOnlinePayment('payfast');
    } else if (actionId === 'remind') {
        sendReminder();
    } else if (actionId === 'void') {
        voidInvoice();
    } else if (actionId === 'unvoid') {
        unvoidInvoice();
    } else if (actionId === 'clone') {
        router.visit(route('invoicing.invoices.create', { from: props.invoice.id }));
    } else if (actionId === 'delete') {
        deleteInvoice();
    }
};
</script>

<template>
    <AppLayout
        :title="`${invoice.number} · ${clientDisplayName}`"
        :breadcrumbs="[
            { label: 'Money In', href: route('invoicing.invoices.index') },
            { label: 'Invoices', href: route('invoicing.invoices.index') },
            { label: invoice.number },
        ]"
    >
        <PageHeader
            :title="invoice.number"
            :subtitle="headerSubtitle"
        >
            <template #actions>
                <AppButton variant="secondary" size="sm" @click="openPdfPreview">
                    <Eye class="mr-1 h-4 w-4 shrink-0" /> Preview
                </AppButton>
                <AppButton variant="secondary" size="sm" @click="downloadPdf">
                    <Download class="mr-1 h-4 w-4 shrink-0" /> Download
                </AppButton>
                <AppButton
                    v-if="can.edit"
                    variant="secondary"
                    size="sm"
                    @click="router.visit(route('invoicing.invoices.edit', invoice.id))"
                >
                    <Edit3 class="mr-1 h-4 w-4 shrink-0" /> Edit
                </AppButton>
                <AppButton
                    v-if="can.send"
                    variant="primary"
                    size="sm"
                    :loading="sendingInvoice"
                    @click="sendInvoice"
                >
                    <Mail class="mr-1 h-4 w-4 shrink-0" />
                    {{ invoice.status === 'draft' ? 'Send' : 'Resend' }}
                </AppButton>
                <AppButton
                    v-if="canRecordPayment"
                    variant="primary"
                    size="sm"
                    @click="openRecordPayment"
                >
                    <Wallet class="mr-1 h-4 w-4 shrink-0" /> Record payment
                </AppButton>
                <InvoiceRowActionsMenu
                    v-if="overflowActions.length"
                    :actions="overflowActions"
                    :aria-label="`More actions for ${invoice.number}`"
                    @select="onOverflowAction"
                />
            </template>
        </PageHeader>

        <div class="grid gap-6 xl:grid-cols-3">
            <section class="xl:col-span-2">
                <AppCard class="space-y-5">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">From</p>
                            <p class="mt-1 text-sm font-medium text-slate-900">{{ issuer.name }}</p>
                            <div v-if="issuerAddressLines.length" class="mt-0.5 space-y-0.5">
                                <p
                                    v-for="(line, index) in issuerAddressLines"
                                    :key="index"
                                    class="text-sm leading-snug text-slate-600"
                                >
                                    {{ line }}
                                </p>
                            </div>
                            <p v-if="issuer.email" class="mt-1 text-sm text-slate-600">{{ issuer.email }}</p>
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
                        <div v-if="invoice.amount_paid_cents > 0" class="flex items-center justify-between text-slate-600"><span>Amount paid</span><span>{{ formatCents(invoice.amount_paid_cents) }}</span></div>
                        <div v-if="invoice.amount_paid_cents > 0" class="flex items-center justify-between font-medium text-slate-900"><span>Outstanding</span><span>{{ formatCents(invoice.amount_due_cents) }}</span></div>
                    </div>

                    <InvoiceInternalCurrencyApprox
                        class="mt-3"
                        :invoice-currency="invoice.currency"
                        :company-currency="business_currency"
                        :total-cents="invoice.total_cents"
                        :amount-due-cents="invoice.amount_due_cents"
                        :book-snapshot="bookCurrencySnapshot"
                    />

                    <div v-if="invoice.notes || invoice.notes_html" class="rounded-md border border-slate-200 p-3 text-sm text-slate-700">
                        <p class="mb-1 text-xs uppercase tracking-wide text-slate-500">Notes</p>
                        <MarkdownProse :html="invoice.notes_html" :text="invoice.notes" />
                    </div>
                    <div v-if="invoice.footer || invoice.footer_html" class="rounded-md border border-slate-200 p-3 text-sm text-slate-700">
                        <p class="mb-1 text-xs uppercase tracking-wide text-slate-500">Footer</p>
                        <MarkdownProse :html="invoice.footer_html" :text="invoice.footer" />
                    </div>
                </AppCard>
            </section>

            <aside class="space-y-4">
                <AppCard class="overflow-hidden p-0">
                    <div class="flex items-center justify-between gap-3 border-b border-canvas-200 bg-canvas-100 px-5 py-3">
                        <h3 class="text-base font-semibold text-slate-900">Status</h3>
                        <AppBadge class="capitalize" :variant="statusBadgeVariant">{{ statusLabel }}</AppBadge>
                    </div>
                    <div class="space-y-2 px-5 py-4">
                        <div v-for="step in timeline" :key="step.label" class="flex items-start gap-2 text-sm">
                            <CircleDot :class="step.done ? 'text-brand-500' : 'text-slate-300'" class="mt-0.5 h-4 w-4" />
                            <div>
                                <p class="font-medium text-slate-800">{{ step.label }}</p>
                                <p class="text-xs text-slate-500">{{ step.at ? new Date(step.at).toLocaleString() : 'Pending' }}</p>
                            </div>
                        </div>
                    </div>
                </AppCard>

                <AppCard class="overflow-hidden p-0">
                    <div class="border-b border-canvas-200 bg-canvas-100 px-5 py-3">
                        <h3 class="text-base font-semibold text-slate-900">Client details</h3>
                    </div>
                    <div class="space-y-0.5 px-5 py-4">
                        <p class="text-sm text-slate-700">{{ invoice.client.name || 'Unknown client' }}</p>
                        <p class="text-sm text-slate-600">{{ invoice.client.email || '-' }}</p>
                        <p class="text-sm text-slate-600">{{ invoice.client.phone || '-' }}</p>
                    </div>
                </AppCard>

                <AppCard v-if="showPublicPayCard" class="overflow-hidden p-0">
                    <div class="border-b border-canvas-200 bg-canvas-100 px-5 py-3">
                        <div class="flex items-start gap-2">
                            <QrCode class="mt-0.5 h-5 w-5 shrink-0 text-slate-600" aria-hidden="true" />
                            <div class="min-w-0 flex-1">
                                <h3 class="text-base font-semibold text-slate-900">Customer pay link</h3>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    QR for in-person or print. Opens a page with this invoice, PDF download, and online payment
                                    (when configured).
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="px-5 py-4">
                        <div v-if="!public_pay_url && can_manage_public_pay_link">
                            <AppButton variant="secondary" type="button" @click="createPublicPayLink">Create pay link &amp; QR</AppButton>
                        </div>
                        <div v-else-if="public_pay_url" class="space-y-3">
                            <div class="flex justify-center rounded-md border border-slate-200 bg-white p-3">
                                <img
                                    v-if="public_pay_qr_url"
                                    :src="public_pay_qr_url"
                                    alt="QR code linking to customer pay page"
                                    class="h-44 w-44"
                                    width="220"
                                    height="220"
                                >
                            </div>
                            <p class="break-all font-mono text-xs text-slate-700">{{ public_pay_url }}</p>
                            <div class="flex flex-wrap gap-2">
                                <AppButton size="sm" variant="secondary" type="button" @click="copyPublicPayUrl">Copy link</AppButton>
                                <AppButton size="sm" variant="secondary" type="button" @click="openPublicPayPage">Open</AppButton>
                                <AppButton
                                    v-if="can_manage_public_pay_link"
                                    size="sm"
                                    variant="ghost"
                                    class="text-amber-900 hover:bg-amber-50"
                                    type="button"
                                    @click="regeneratePublicPayLink"
                                >
                                    New link
                                </AppButton>
                            </div>
                        </div>
                    </div>
                </AppCard>

                <AppCard class="overflow-hidden p-0">
                    <div class="flex items-center justify-between gap-3 border-b border-canvas-200 bg-canvas-100 px-5 py-3">
                        <h3 class="text-base font-semibold text-slate-900">Payment history</h3>
                        <AppButton v-if="can.record_payment" size="sm" variant="secondary" @click="openRecordPayment">Record Payment</AppButton>
                    </div>
                    <div v-if="invoice.payments.length" class="divide-y divide-slate-100">
                        <div
                            v-for="payment in invoice.payments"
                            :key="payment.id"
                            class="px-5 py-3 text-sm"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-medium text-slate-900">{{ formatCents(payment.amount_cents) }}</p>
                                    <p
                                        v-if="isForeignCurrencyInvoice && paymentBankAmountCents(payment) != null"
                                        class="text-xs text-slate-600"
                                    >
                                        Received {{ formatBusinessCents(paymentBankAmountCents(payment)!) }} in bank
                                    </p>
                                    <p class="text-xs text-slate-500">{{ payment.payment_date }} • {{ payment.method.toUpperCase() }}</p>
                                </div>
                                <AppButton
                                    v-if="payment.can_undo"
                                    size="sm"
                                    variant="ghost"
                                    class="shrink-0 text-amber-900 hover:bg-amber-50"
                                    @click="undoPayment(payment.id)"
                                >
                                    Undo
                                </AppButton>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <AppButton size="sm" variant="secondary" type="button" @click="openReceiptPreview(payment.id)">
                                    <Eye class="mr-1 h-3.5 w-3.5" /> Preview
                                </AppButton>
                                <AppButton size="sm" variant="secondary" type="button" @click="downloadReceipt(payment.id)">
                                    <Download class="mr-1 h-3.5 w-3.5" /> Download
                                </AppButton>
                                <AppButton
                                    size="sm"
                                    variant="secondary"
                                    type="button"
                                    :loading="sendingReceiptId === payment.id"
                                    @click="sendReceipt(payment.id)"
                                >
                                    <Mail class="mr-1 h-3.5 w-3.5" /> Send
                                </AppButton>
                            </div>
                        </div>
                    </div>
                    <p v-else class="px-5 py-4 text-sm text-slate-500">No payments recorded yet.</p>
                </AppCard>

                <AppCard class="overflow-hidden p-0">
                    <div class="border-b border-canvas-200 bg-canvas-100 px-5 py-3">
                        <h3 class="text-base font-semibold text-slate-900">Activity log</h3>
                    </div>
                    <div v-if="invoice.activity_log.length">
                        <div class="divide-y divide-slate-100">
                            <div
                                v-for="entry in visibleActivityLog"
                                :key="entry.id"
                                class="px-5 py-3 text-sm"
                            >
                                <p class="text-slate-800">{{ entry.description }}</p>
                                <p class="text-xs text-slate-500">{{ entry.created_at ? new Date(entry.created_at).toLocaleString() : '-' }}</p>
                            </div>
                        </div>
                        <button
                            v-if="hiddenActivityCount > 0"
                            type="button"
                            class="w-full border-t border-slate-100 px-5 py-2.5 text-sm font-medium text-brand-700 hover:bg-brand-50"
                            @click="activityLogExpanded = !activityLogExpanded"
                        >
                            {{ activityLogExpanded ? 'Show less' : `Show ${hiddenActivityCount} more` }}
                        </button>
                    </div>
                    <p v-else class="px-5 py-4 text-sm text-slate-500">No activity logged yet.</p>
                </AppCard>

            </aside>
        </div>

        <RecordInvoicePaymentDrawer
            :open="paymentDrawerOpen"
            :invoice="paymentDrawerOpen ? recordPaymentInvoice : null"
            :charges-vat="charges_vat"
            @update:open="paymentDrawerOpen = $event"
        />

        <div
            v-if="pdfPreviewOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-label="Invoice PDF preview"
            @click.self="pdfPreviewOpen = false"
        >
            <div class="flex h-[min(92vh,56rem)] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900">PDF preview · {{ invoice.number }}</p>
                        <p class="truncate text-xs text-slate-500">Same document as the downloadable invoice PDF</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <AppButton
                            size="sm"
                            variant="secondary"
                            type="button"
                            @click="openPdfInNewTab"
                        >
                            Open in new tab
                        </AppButton>
                        <AppButton size="sm" variant="primary" type="button" @click="downloadPdf">
                            <Download class="mr-1 h-4 w-4 shrink-0" /> Download
                        </AppButton>
                        <button
                            type="button"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                            aria-label="Close PDF preview"
                            @click="pdfPreviewOpen = false"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </div>
                <iframe
                    :src="pdfPreviewUrl"
                    class="h-full w-full flex-1 bg-slate-100"
                    title="Invoice PDF preview"
                />
            </div>
        </div>

        <div
            v-if="receiptPreviewOpen && receiptPreviewPaymentId != null"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-label="Payment receipt PDF preview"
            @click.self="receiptPreviewOpen = false"
        >
            <div class="flex h-[min(92vh,56rem)] w-full max-w-5xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-900">Receipt preview · {{ invoice.number }}</p>
                        <p class="truncate text-xs text-slate-500">Payment receipt with invoice total and outstanding balance</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <AppButton size="sm" variant="secondary" type="button" @click="openReceiptInNewTab">
                            Open in new tab
                        </AppButton>
                        <AppButton size="sm" variant="primary" type="button" @click="downloadReceipt(receiptPreviewPaymentId)">
                            <Download class="mr-1 h-4 w-4 shrink-0" /> Download
                        </AppButton>
                        <button
                            type="button"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                            aria-label="Close receipt preview"
                            @click="receiptPreviewOpen = false"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </div>
                <iframe
                    :src="receiptPreviewUrl"
                    class="h-full w-full flex-1 bg-slate-100"
                    title="Payment receipt PDF preview"
                />
            </div>
        </div>
    </AppLayout>
</template>
