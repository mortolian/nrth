<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import Sortable from 'sortablejs';
import AppLayout from '@/Layouts/AppLayout.vue';
import InvoiceInternalCurrencyApprox from '@/Components/InvoiceInternalCurrencyApprox.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { GripVertical, Plus, Trash2 } from 'lucide-vue-next';

type ClientOption = { id: number; name: string; payment_terms_days: number; currency: string };
type TaxRateOption = { id: number; name: string; rate: number; is_default: boolean };
type AccountOption = { id: number; name: string };
type InvoiceLine = {
    id?: number;
    row_key: string;
    description: string;
    quantity: number;
    unit_price: string;
    vat_rate: number;
    account_id?: number | null;
};

const props = defineProps<{
    /** When false, VAT is not applied (company not VAT-registered or no default VAT rate). */
    charges_vat: boolean;
    isEditing: boolean;
    invoice: null | {
        id: number;
        number: string;
        client_id: number;
        reference: string | null;
        issue_date: string | null;
        due_date: string | null;
        notes: string | null;
        footer: string | null;
        amount_paid_cents: number;
        amount_due_cents: number;
        currency: string;
        company_currency_code?: string | null;
        fx_rate_invoice_to_company?: string | null;
        fx_rate_date?: string | null;
        total_company_currency_cents?: number | null;
        line_items: InvoiceLine[];
    };
    clients: ClientOption[];
    tax_rates: TaxRateOption[];
    accounts: AccountOption[];
    next_number: string;
    /** Company default when no client or before client selection. */
    default_currency: string;
    defaults?: {
        payment_terms_days: number;
        notes: string;
        footer: string;
    };
}>();

const chargesVat = computed(() => props.charges_vat);

const defaultVatRate = computed(() => {
    if (!chargesVat.value) {
        return 0;
    }
    return props.tax_rates.find((rate) => rate.is_default)?.rate ?? props.tax_rates[0]?.rate ?? 0;
});
const makeRowKey = () => `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;

const newClientHref = computed(() => route('invoicing.clients.create', {
    return: '/invoicing/invoices/create',
}));
const goToCreateClient = () => {
    window.location.assign(String(newClientHref.value));
};

const hasClients = computed(() => props.clients.length > 0);
const canSaveInvoice = computed(() => props.isEditing || hasClients.value);

const initialClientId = props.invoice?.client_id ?? props.clients[0]?.id ?? null;
const clientForInitialCurrency = initialClientId
    ? props.clients.find((c) => c.id === initialClientId)
    : null;
const initialInvoiceCurrency =
    props.invoice?.currency
    ?? clientForInitialCurrency?.currency
    ?? props.default_currency
    ?? 'ZAR';

const invoiceSchema = z.object({
    client_id: z.coerce.number().int().positive('Client is required'),
    number: z.string().min(1, 'Invoice number is required'),
    reference: z.string().optional(),
    issue_date: z.string().min(1, 'Issue date is required'),
    due_date: z.string().min(1, 'Due date is required'),
    currency: z
        .string()
        .length(3, 'Select a currency')
        .regex(/^[A-Z]{3}$/, 'Use a 3-letter ISO currency code'),
    notes: z.string().optional(),
    footer: z.string().optional(),
    line_items: z.array(z.object({
        description: z.string().min(1, 'Description is required'),
        quantity: z.coerce.number().positive('Qty must be greater than 0'),
        unit_price: z.coerce.number().min(0, 'Unit price cannot be negative'),
        vat_rate: z.coerce.number().min(0).max(1),
        account_id: z.coerce.number().nullable().optional(),
    })).min(1, 'Add at least one line item'),
});

const page = usePage();
const currencyOptions = computed(
    () => (page.props.currencyOptions as Array<{ value: string; label: string }>) ?? [],
);

const inertiaErrors = computed(() => {
    const raw = page.props.errors as Record<string, string | string[] | undefined>;
    if (!raw || typeof raw !== 'object') {
        return [] as { key: string; message: string }[];
    }
    return Object.entries(raw).flatMap(([key, val]) => {
        if (val === undefined || val === null) {
            return [];
        }
        const message = Array.isArray(val) ? val.join(' ') : String(val);

        return [{ key, message }];
    });
});

/** Options for VAT select when VAT applies. */
const taxRateSelectOptions = computed(() => {
    if (!chargesVat.value) {
        return [{ label: 'No VAT', value: '0' }];
    }
    if (props.tax_rates.length) {
        return props.tax_rates.map((rate) => ({ label: rate.name, value: String(rate.rate) }));
    }

    return [{ label: 'No VAT', value: '0' }];
});

const { setErrors, values, setFieldValue } = useForm({
    initialValues: {
        client_id: initialClientId,
        number: props.invoice?.number ?? props.next_number,
        reference: props.invoice?.reference ?? '',
        issue_date: props.invoice?.issue_date ?? new Date().toISOString().slice(0, 10),
        due_date: props.invoice?.due_date ?? new Date().toISOString().slice(0, 10),
        currency: initialInvoiceCurrency,
        notes: props.invoice?.notes ?? props.defaults?.notes ?? '',
        footer: props.invoice?.footer ?? props.defaults?.footer ?? '',
        line_items: props.invoice?.line_items?.length
            ? props.invoice.line_items.map((line) => ({
                row_key: makeRowKey(),
                description: line.description,
                quantity: Number(line.quantity) || 1,
                unit_price: (Number(line.unit_price) || 0).toFixed(2),
                vat_rate: Number(line.vat_rate) || defaultVatRate.value,
                account_id: line.account_id ?? null,
            }))
            : [{ row_key: makeRowKey(), description: '', quantity: 1, unit_price: '0.00', vat_rate: defaultVatRate.value, account_id: null }],
    },
});

/**
 * vee-validate `values` can be returned as either a ref-like wrapper or a reactive object
 * depending on version/build. Normalize once so watcher getters never crash.
 */
const formValues = computed<Record<string, any>>(() => ((values as any)?.value ?? values) as Record<string, any>);

const lineItemsTbodyRef = ref<HTMLTableSectionElement | null>(null);
let lineItemSortable: ReturnType<typeof Sortable.create> | null = null;

const lineItemsOrderSignature = computed(() =>
    ((formValues.value.line_items ?? []) as InvoiceLine[]).map((l) => l.row_key).join('|'),
);

const initLineItemSortable = () => {
    lineItemSortable?.destroy();
    lineItemSortable = null;
    const el = lineItemsTbodyRef.value;
    if (!el || el.querySelectorAll('tr').length === 0) {
        return;
    }
    lineItemSortable = Sortable.create(el, {
        animation: 150,
        handle: '.line-drag-handle',
        draggable: 'tr',
        onEnd(evt) {
            const { oldIndex, newIndex } = evt;
            if (oldIndex === undefined || newIndex === undefined || oldIndex === newIndex) {
                return;
            }
            const lines = [...((formValues.value.line_items ?? []) as InvoiceLine[])];
            const [moved] = lines.splice(oldIndex, 1);
            lines.splice(newIndex, 0, moved);
            setFieldValue('line_items', lines);
        },
    });
};

onMounted(() => {
    nextTick(() => initLineItemSortable());
});

watch(lineItemsOrderSignature, () => {
    nextTick(() => initLineItemSortable());
}, { flush: 'post' });

onBeforeUnmount(() => {
    lineItemSortable?.destroy();
    lineItemSortable = null;
});

const clientMap = computed<Record<number, ClientOption>>(() => (
    props.clients.reduce((acc, client) => {
        acc[client.id] = client;
        return acc;
    }, {} as Record<number, ClientOption>)
));

watch(
    () => [formValues.value?.client_id, formValues.value?.issue_date],
    ([clientId, issueDate]) => {
        if (!issueDate || !clientId) return;
        const client = clientMap.value[Number(clientId)];
        if (!client) return;
        const base = new Date(issueDate);
        base.setDate(base.getDate() + client.payment_terms_days);
        setFieldValue('due_date', base.toISOString().slice(0, 10));
    },
    { immediate: true },
);

watch(
    () => formValues.value?.client_id,
    (clientId) => {
        if (clientId == null) return;
        const client = clientMap.value[Number(clientId)];
        if (client?.currency) {
            setFieldValue('currency', client.currency);
        }
    },
);

const lineSubtotal = (line: InvoiceLine) => Math.round((Number(line.quantity) || 0) * (Number(line.unit_price) || 0) * 100);
const lineVat = (line: InvoiceLine) => Math.round(lineSubtotal(line) * (Number(line.vat_rate) || 0));
const lineTotal = (line: InvoiceLine) => lineSubtotal(line) + lineVat(line);

const totals = computed(() => {
    const lines = formValues.value.line_items ?? [];
    const subtotal = lines.reduce((sum, line) => sum + lineSubtotal(line as InvoiceLine), 0);
    const vat = lines.reduce((sum, line) => sum + lineVat(line as InvoiceLine), 0);
    const total = subtotal + vat;
    const amountPaid = props.invoice?.amount_paid_cents ?? 0;
    const amountDue = Math.max(0, total - amountPaid);

    const vatBreakdown = lines.reduce((acc, rawLine) => {
        const line = rawLine as InvoiceLine;
        const key = `${Math.round((line.vat_rate ?? 0) * 100)}%`;
        const current = acc[key] ?? 0;
        acc[key] = current + lineVat(line);
        return acc;
    }, {} as Record<string, number>);

    return { subtotal, vat, total, amountPaid, amountDue, vatBreakdown };
});

const displayCurrency = computed(() => (formValues.value.currency as string) || 'ZAR');
const formatCents = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, displayCurrency.value);

const addLine = () => {
    const next = [...(formValues.value.line_items ?? []), {
        row_key: makeRowKey(),
        description: '',
        quantity: 1,
        unit_price: '0.00',
        vat_rate: defaultVatRate.value,
        account_id: null,
    }];
    setFieldValue('line_items', next);
};

const updateLine = (index: number, field: keyof InvoiceLine, value: any) => {
    const next = (formValues.value.line_items ?? []).map((line: InvoiceLine, i: number) => (
        i === index ? { ...line, [field]: value } : line
    ));
    setFieldValue('line_items', next);
};

const normalizeMoneyInput = (raw: unknown): string => {
    const cleaned = String(raw ?? '').trim().replace(',', '.');
    if (cleaned === '') return '0.00';
    const parsed = Number(cleaned);
    if (!Number.isFinite(parsed) || parsed < 0) return '0.00';
    return parsed.toFixed(2);
};

const onUnitPriceBlur = (index: number) => {
    const line = (formValues.value.line_items ?? [])[index] as InvoiceLine | undefined;
    if (!line) return;
    updateLine(index, 'unit_price', normalizeMoneyInput(line.unit_price));
};

const removeLine = (index: number) => {
    const next = [...(formValues.value.line_items ?? [])];
    next.splice(index, 1);
    setFieldValue('line_items', next.length ? next : [{
        row_key: makeRowKey(),
        description: '',
        quantity: 1,
        unit_price: '0.00',
        vat_rate: defaultVatRate.value,
        account_id: null,
    }]);
};

const onSave = () => {
    // Read `values` directly: nested line rows use v-model on shared objects; vee-validate's
    // handleSubmit() can pass a stale snapshot that omits those edits.
    const result = invoiceSchema.safeParse(formValues.value);
    if (!result.success) {
        const mapped: Record<string, string> = {};
        for (const issue of result.error.issues) {
            mapped[issue.path.join('.')] = issue.message;
        }
        setErrors(mapped);
        return;
    }

    const { line_items: lineItems, ...rest } = result.data;
    const payload = {
        ...rest,
        line_items: lineItems.map((line) => ({
            description: line.description,
            quantity: Number(line.quantity),
            unit_price_cents: Math.round(Number(line.unit_price) * 100),
            vat_rate: Number(line.vat_rate),
        })),
    };

    if (props.isEditing && props.invoice?.id) {
        router.put(route('invoicing.invoices.update', props.invoice.id), payload);
        return;
    }

    router.post(route('invoicing.invoices.store'), payload);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? `Edit ${invoice?.number}` : 'New Invoice'"
        :breadcrumbs="[
            { label: 'Invoicing' },
            { label: 'Invoices', href: route('invoicing.invoices.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader :title="isEditing ? `Edit ${invoice?.number}` : 'Create Invoice'" />

        <div
            v-if="inertiaErrors.length"
            class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
        >
            <p class="font-medium">Could not save invoice</p>
            <ul class="mt-2 list-inside list-disc space-y-1 text-rose-800">
                <li v-for="err in inertiaErrors" :key="err.key">{{ err.message }}</li>
            </ul>
        </div>

        <div class="mt-5 space-y-6">
                <AppCard>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Client</label>
                            <div
                                v-if="!hasClients"
                                class="rounded-md border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-950"
                            >
                                <p class="font-medium">You need at least one client</p>
                                <p class="mt-1 text-amber-900/90">Create a client first, then you can fill in this invoice.</p>
                                <button
                                    type="button"
                                    class="mt-2 inline-block text-sm font-medium text-brand-700 underline hover:text-brand-800"
                                    @click="goToCreateClient"
                                >
                                    Create a client
                                </button>
                            </div>
                            <template v-else>
                                <AppSelect
                                    :model-value="String(values.client_id ?? '')"
                                    :options="clients.map((client) => ({ label: client.name, value: String(client.id) }))"
                                    placeholder="Select client"
                                    @update:model-value="setFieldValue('client_id', Number($event))"
                                />
                                <button
                                    type="button"
                                    class="mt-2 inline-block text-xs text-brand-700 hover:underline"
                                    @click="goToCreateClient"
                                >
                                    + Add new client
                                </button>
                            </template>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Invoice number</label>
                            <AppInput :model-value="values.number as string" @update:model-value="setFieldValue('number', $event)" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Reference</label>
                            <AppInput :model-value="values.reference as string" @update:model-value="setFieldValue('reference', $event)" placeholder="PO number etc." />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Issue date</label>
                                <AppInput type="date" :model-value="values.issue_date as string" @update:model-value="setFieldValue('issue_date', $event)" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Due date</label>
                                <AppInput type="date" :model-value="values.due_date as string" @update:model-value="setFieldValue('due_date', $event)" />
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Invoice currency</label>
                            <AppSelect
                                :model-value="values.currency as string"
                                :options="currencyOptions"
                                @update:model-value="setFieldValue('currency', $event)"
                            />
                            <p class="mt-1 text-xs text-slate-500">
                                Defaults to the selected client&rsquo;s currency; change here to override for this invoice only.
                            </p>
                        </div>
                    </div>
                </AppCard>

                <AppCard>
                    <p v-if="!chargesVat" class="mb-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        VAT is not applied on this invoice. Enable VAT registered and choose a default VAT rate in Company settings to charge VAT.
                    </p>
                    <h3 class="mb-3 text-base font-semibold text-slate-900">Line items</h3>

                    <div class="-mx-1 overflow-x-auto px-1 [scrollbar-width:thin]">
                        <table class="w-full min-w-[52rem] table-fixed divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="w-10 px-1 py-2.5 text-center" scope="col"><span class="sr-only">Drag to reorder</span></th>
                                    <th class="w-[40%] min-w-[12rem] px-3 py-2.5 text-left font-medium">Description</th>
                                    <th class="w-16 px-2 py-2.5 text-right font-medium">Qty</th>
                                    <th class="w-28 px-2 py-2.5 text-right font-medium">Unit price</th>
                                    <th v-if="chargesVat" class="w-32 px-2 py-2.5 text-left font-medium">VAT</th>
                                    <th v-if="chargesVat" class="w-24 px-2 py-2.5 text-right font-medium">VAT amt</th>
                                    <th class="w-28 px-2 py-2.5 text-right font-medium">Line total</th>
                                    <th class="w-11 px-1 py-2.5 text-center font-medium"><span class="sr-only">Remove</span></th>
                                </tr>
                            </thead>
                            <tbody ref="lineItemsTbodyRef" class="divide-y divide-slate-100">
                                <tr v-for="(line, index) in (values.line_items as InvoiceLine[])" :key="line.row_key">
                                    <td class="w-10 px-1 py-3 align-top">
                                        <span
                                            class="line-drag-handle mt-1 inline-flex cursor-grab touch-manipulation rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 active:cursor-grabbing"
                                            role="button"
                                            tabindex="0"
                                            aria-label="Drag to reorder line"
                                        >
                                            <GripVertical class="h-4 w-4 shrink-0" />
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <div class="space-y-2">
                                            <textarea
                                                :value="line.description"
                                                rows="3"
                                                placeholder="What you delivered or sold"
                                                class="min-h-[4.5rem] w-full resize-y rounded-md border border-slate-300 bg-white px-3 py-2 text-sm leading-snug text-slate-900 outline-none ring-slate-300 transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                                                @input="updateLine(index, 'description', ($event.target as HTMLTextAreaElement).value)"
                                            />
                                            <AppSelect
                                                v-if="accounts.length"
                                                :model-value="line.account_id ? String(line.account_id) : ''"
                                                :options="accounts.map((account) => ({ label: account.name, value: String(account.id) }))"
                                                placeholder="Income account"
                                                @update:model-value="updateLine(index, 'account_id', Number($event))"
                                            />
                                        </div>
                                    </td>
                                    <td class="px-2 py-3 align-top">
                                        <AppInput
                                            class="text-right tabular-nums"
                                            :model-value="line.quantity"
                                            type="number"
                                            inputmode="decimal"
                                            @update:model-value="updateLine(index, 'quantity', Number($event))"
                                        />
                                    </td>
                                    <td class="px-2 py-3 align-top">
                                        <AppInput
                                            class="text-right tabular-nums"
                                            :model-value="line.unit_price"
                                            type="text"
                                            inputmode="decimal"
                                            step="0.01"
                                            pattern="^\\d*(\\.\\d{0,2})?$"
                                            @update:model-value="updateLine(index, 'unit_price', $event)"
                                            @blur="onUnitPriceBlur(index)"
                                        />
                                    </td>
                                    <td v-if="chargesVat" class="px-2 py-3 align-top">
                                        <AppSelect
                                            :model-value="String(line.vat_rate)"
                                            :options="taxRateSelectOptions"
                                            @update:model-value="updateLine(index, 'vat_rate', Number($event))"
                                        />
                                    </td>
                                    <td v-if="chargesVat" class="px-2 py-3 align-top text-right tabular-nums text-slate-700">
                                        {{ formatCents(lineVat(line)) }}
                                    </td>
                                    <td class="px-2 py-3 align-top text-right text-base font-semibold tabular-nums text-slate-900">
                                        {{ formatCents(lineTotal(line)) }}
                                    </td>
                                    <td class="px-1 py-3 align-top text-center">
                                        <button
                                            class="mt-1 rounded p-1.5 text-rose-600 hover:bg-rose-50"
                                            type="button"
                                            :aria-label="`Remove line ${index + 1}`"
                                            @click="removeLine(index)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3 flex justify-center border-t border-slate-200 pt-3">
                        <AppButton size="sm" variant="secondary" @click="addLine">
                            <Plus class="mr-1 h-4 w-4" />
                            Add line item
                        </AppButton>
                    </div>
                </AppCard>

                <AppCard>
                    <h3 class="mb-3 text-base font-semibold text-slate-900">Totals</h3>
                    <div class="space-y-2 text-sm text-slate-700">
                        <div class="flex items-center justify-between">
                            <span>{{ chargesVat ? 'Subtotal (excl VAT)' : 'Subtotal' }}</span>
                            <span>{{ formatCents(totals.subtotal) }}</span>
                        </div>
                        <template v-if="chargesVat">
                            <div v-for="(amount, key) in totals.vatBreakdown" :key="key" class="flex items-center justify-between">
                                <span>VAT {{ key }}</span>
                                <span>{{ formatCents(amount) }}</span>
                            </div>
                        </template>
                        <div class="flex items-center justify-between border-t border-slate-200 pt-2 font-semibold">
                            <span>{{ chargesVat ? 'Total (incl VAT)' : 'Total' }}</span>
                            <span>{{ formatCents(totals.total) }}</span>
                        </div>
                        <div v-if="isEditing" class="flex items-center justify-between"><span>Amount paid</span><span>{{ formatCents(totals.amountPaid) }}</span></div>
                        <div class="flex items-center justify-between text-base font-bold text-slate-900"><span>Amount due</span><span>{{ formatCents(totals.amountDue) }}</span></div>
                    </div>
                    <InvoiceInternalCurrencyApprox
                        class="mt-3"
                        :invoice-currency="displayCurrency"
                        :company-currency="default_currency"
                        :total-cents="totals.total"
                        :amount-due-cents="totals.amountDue"
                    />
                </AppCard>

                <AppCard>
                    <h3 class="mb-3 text-base font-semibold text-slate-900">Details</h3>
                    <div class="grid gap-6 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                            <textarea
                                :value="values.notes as string"
                                class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                                @input="setFieldValue('notes', ($event.target as HTMLTextAreaElement).value)"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Footer</label>
                            <textarea
                                :value="values.footer as string"
                                class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                                @input="setFieldValue('footer', ($event.target as HTMLTextAreaElement).value)"
                            />
                        </div>
                    </div>
                </AppCard>
        </div>

        <div class="sticky bottom-0 mt-6 border-t border-slate-200 bg-white/95 px-2 py-3 backdrop-blur">
            <div class="flex items-center justify-end gap-2">
                <AppButton variant="ghost" @click="router.visit(route('invoicing.invoices.index'))">Cancel</AppButton>
                <AppButton variant="primary" :disabled="!canSaveInvoice" @click="onSave">Save</AppButton>
            </div>
        </div>
    </AppLayout>
</template>
