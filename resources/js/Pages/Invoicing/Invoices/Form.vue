<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import Sortable from 'sortablejs';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormValidationBanner from '@/Components/FormValidationBanner.vue';
import FieldHelp from '@/Components/FieldHelp.vue';
import InvoiceInternalCurrencyApprox from '@/Components/InvoiceInternalCurrencyApprox.vue';
import MarkdownEditor from '@/Components/MarkdownEditor.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { calculateInvoiceTotals, type DiscountType } from '@/Composables/useInvoiceTotals';
import { useToast } from '@/Composables/useToast';
import { GripVertical, Plus, Trash2 } from 'lucide-vue-next';

type ClientOption = {
    id: number;
    name: string;
    payment_terms_days: number;
    currency: string;
    default_notes?: string;
};
type TaxRateOption = { id: number; name: string; rate: number; is_default: boolean };
type AccountOption = { id: number; name: string };
type NoteTemplateOption = { id: number; name: string; body: string; target: 'notes' | 'footer' };
type CatalogItemOption = {
    id: number;
    name: string;
    description: string | null;
    unit: string | null;
    unit_price_cents: number;
    default_vat_rate: number | null;
};
type InvoiceLine = {
    id?: number;
    row_key: string;
    item_id?: number | null;
    description: string;
    quantity: number;
    unit_price: string;
    vat_rate: number;
    income_account_id?: number | null;
    discount_type?: 'percent' | 'fixed' | null;
    discount_percent?: number | null;
    discount_amount?: string;
};

const props = defineProps<{
    /** When false, VAT fields are hidden (company not VAT-registered). */
    charges_vat: boolean;
    /** Team default VAT rate (0–1); 0 = zero-rated when charges_vat. */
    default_vat_rate?: number;
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
        business_currency_code?: string | null;
        fx_rate_invoice_to_business?: string | null;
        fx_rate_date?: string | null;
        total_business_currency_cents?: number | null;
        discount_type?: 'percent' | 'fixed' | null;
        discount_percent?: number | null;
        discount_cents?: number | null;
        income_account_id?: number | null;
        line_items: Array<InvoiceLine & { discount_cents?: number | null }>;
    };
    clients: ClientOption[];
    items?: CatalogItemOption[];
    tax_rates: TaxRateOption[];
    accounts: AccountOption[];
    default_income_account_id?: number | null;
    note_templates?: NoteTemplateOption[];
    next_number: string;
    /** Business default when no client or before client selection. */
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
    if (props.default_vat_rate != null && Number.isFinite(Number(props.default_vat_rate))) {
        return Number(props.default_vat_rate);
    }
    return props.tax_rates.find((rate) => rate.is_default)?.rate ?? props.tax_rates[0]?.rate ?? 0;
});
const makeRowKey = () => `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;

const discountTypeOptions = [
    { label: 'No discount', value: '' },
    { label: 'Percent %', value: 'percent' },
    { label: 'Fixed amount', value: 'fixed' },
];

const fixedDiscountFromCents = (cents: number | null | undefined): string =>
    cents != null ? (Number(cents) / 100).toFixed(2) : '0.00';

const mapLineFromInvoice = (line: InvoiceLine & { discount_cents?: number | null }): InvoiceLine => ({
    row_key: makeRowKey(),
    id: line.id,
    item_id: line.item_id ?? null,
    description: line.description,
    quantity: Number(line.quantity) || 1,
    unit_price: (Number(line.unit_price) || 0).toFixed(2),
    vat_rate: line.vat_rate != null && line.vat_rate !== '' ? Number(line.vat_rate) : defaultVatRate.value,
    income_account_id: line.income_account_id ?? null,
    discount_type: line.discount_type ?? null,
    discount_percent: line.discount_percent ?? null,
    discount_amount: line.discount_type === 'fixed'
        ? fixedDiscountFromCents(line.discount_cents)
        : '0.00',
});

const emptyLine = (): InvoiceLine => ({
    row_key: makeRowKey(),
    item_id: null,
    description: '',
    quantity: 1,
    unit_price: '0.00',
    vat_rate: defaultVatRate.value,
    income_account_id: null,
    discount_type: null,
    discount_percent: null,
    discount_amount: '0.00',
});

const newClientHref = computed(() => route('invoicing.clients.create', {
    return: '/invoicing/invoices/create',
}));
const goToCreateClient = () => {
    window.location.assign(String(newClientHref.value));
};

const hasClients = computed(() => props.clients.length > 0);
const canSaveInvoice = computed(() => props.isEditing || hasClients.value);
const saving = ref(false);
const toast = useToast();
const { fieldErrors, setFromZod, setFromServer, clear, clearField, messages: clientErrorMessages } = useFieldErrors();

const initialClientId = props.invoice?.client_id ?? props.clients[0]?.id ?? null;
const clientForInitialCurrency = initialClientId
    ? props.clients.find((c) => c.id === initialClientId)
    : null;
const initialInvoiceCurrency =
    props.invoice?.currency
    ?? clientForInitialCurrency?.currency
    ?? props.default_currency
    ?? 'ZAR';

const initialIncomeAccountId =
    props.invoice?.income_account_id
    ?? props.default_income_account_id
    ?? null;

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
    discount_type: z.enum(['percent', 'fixed']).nullable().optional(),
    discount_percent: z.coerce.number().min(0).max(100).nullable().optional(),
    discount_amount: z.coerce.number().min(0).optional(),
    income_account_id: z.coerce.number().nullable().optional(),
    line_items: z.array(z.object({
        description: z.string().min(1, 'Description is required'),
        quantity: z.coerce.number().positive('Qty must be greater than 0'),
        unit_price: z.coerce.number().min(0, 'Unit price cannot be negative'),
        vat_rate: z.coerce.number().min(0).max(1),
        income_account_id: z.coerce.number().nullable().optional(),
        item_id: z.coerce.number().nullable().optional(),
        discount_type: z.enum(['percent', 'fixed']).nullable().optional(),
        discount_percent: z.coerce.number().min(0).max(100).nullable().optional(),
        discount_amount: z.coerce.number().min(0).optional(),
    })).min(1, 'Add at least one line item'),
});

const page = usePage();
const currencyOptions = computed(
    () => (page.props.currencyOptions as Array<{ value: string; label: string }>) ?? [],
);

const inertiaErrorMessages = computed(() => {
    const raw = page.props.errors as Record<string, string | string[] | undefined>;
    if (!raw || typeof raw !== 'object') {
        return [] as string[];
    }
    return Object.values(raw).flatMap((val) => {
        if (val === undefined || val === null) {
            return [];
        }
        return [Array.isArray(val) ? val.join(' ') : String(val)];
    });
});

const visibleValidationErrors = computed(() =>
    clientErrorMessages.value.length ? clientErrorMessages.value : inertiaErrorMessages.value,
);

/** Options for VAT select when VAT applies. */
const taxRateSelectOptions = computed(() => {
    if (!chargesVat.value) {
        return [{ label: 'Zero rated (0%)', value: '0' }];
    }

    const options = props.tax_rates.length
        ? props.tax_rates.map((rate) => ({
            label: rate.rate === 0 ? `${rate.name} (zero rated)` : rate.name,
            value: String(rate.rate),
        }))
        : [
            { label: 'Zero rated (0%)', value: '0' },
            { label: '15%', value: '0.15' },
        ];

    const defaultRate = String(defaultVatRate.value);
    if (!options.some((o) => o.value === defaultRate)) {
        const pct = (defaultVatRate.value * 100).toFixed(defaultVatRate.value === 0 ? 0 : 2).replace(/\.?0+$/, '');
        options.unshift({
            label: defaultVatRate.value === 0 ? 'Zero rated (0%)' : `${pct}% (default)`,
            value: defaultRate,
        });
    }

    return options;
});

const accountSelectOptions = computed(() =>
    props.accounts.map((account) => ({ label: account.name, value: String(account.id) }),
));

const lineAccountSelectOptions = computed(() => [
    { label: 'Use invoice default', value: '' },
    ...accountSelectOptions.value,
]);

const notesTemplateOptions = computed(() =>
    (props.note_templates ?? [])
        .filter((template) => template.target === 'notes')
        .map((template) => ({ label: template.name, value: String(template.id) })),
);

const clientNotesFooterDefaults = (client: ClientOption | undefined) => ({
    notes: (client?.default_notes?.trim() ? client.default_notes : props.defaults?.notes) ?? '',
    footer: props.defaults?.footer ?? '',
});

const initialClientDefaults = clientNotesFooterDefaults(
    initialClientId ? props.clients.find((c) => c.id === initialClientId) : undefined,
);

const { values, setFieldValue: setVeeFieldValue } = useForm({
    initialValues: {
        client_id: initialClientId,
        number: props.invoice?.number ?? props.next_number,
        reference: props.invoice?.reference ?? '',
        issue_date: props.invoice?.issue_date ?? new Date().toISOString().slice(0, 10),
        due_date: props.invoice?.due_date ?? new Date().toISOString().slice(0, 10),
        currency: initialInvoiceCurrency,
        notes: props.invoice?.notes ?? initialClientDefaults.notes,
        footer: props.invoice?.footer ?? initialClientDefaults.footer,
        discount_type: props.invoice?.discount_type ?? null,
        discount_percent: props.invoice?.discount_percent ?? null,
        discount_amount: props.invoice?.discount_type === 'fixed'
            ? fixedDiscountFromCents(props.invoice?.discount_cents)
            : '0.00',
        income_account_id: initialIncomeAccountId,
        line_items: props.invoice?.line_items?.length
            ? props.invoice.line_items.map(mapLineFromInvoice)
            : [emptyLine()],
    },
});

const setFieldValue = (path: string, value: unknown) => {
    setVeeFieldValue(path, value);
    clearField(path);
};

/**
 * vee-validate `values` can be returned as either a ref-like wrapper or a reactive object
 * depending on version/build. Normalize once so watcher getters never crash.
 */
const formValues = computed<Record<string, any>>(() => ((values as any)?.value ?? values) as Record<string, any>);

const lineItemsListRef = ref<HTMLElement | null>(null);
let lineItemSortable: ReturnType<typeof Sortable.create> | null = null;

const lineItemsOrderSignature = computed(() =>
    ((formValues.value.line_items ?? []) as InvoiceLine[]).map((l) => l.row_key).join('|'),
);

const initLineItemSortable = () => {
    lineItemSortable?.destroy();
    lineItemSortable = null;
    const el = lineItemsListRef.value;
    if (!el || el.querySelectorAll('.invoice-line-item').length === 0) {
        return;
    }
    lineItemSortable = Sortable.create(el, {
        animation: 150,
        handle: '.line-drag-handle',
        draggable: '.invoice-line-item',
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

const previousClientDefaults = ref({ ...initialClientDefaults });
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

watch(
    () => formValues.value?.client_id,
    (clientId) => {
        if (props.isEditing || clientId == null) return;
        const client = clientMap.value[Number(clientId)];
        if (!client) return;

        const businessNotes = props.defaults?.notes ?? '';
        const businessFooter = props.defaults?.footer ?? '';
        const currentNotes = String(formValues.value.notes ?? '');
        const currentFooter = String(formValues.value.footer ?? '');
        const prevNotes = previousClientDefaults.value.notes;
        const prevFooter = previousClientDefaults.value.footer;

        const notesUnchanged = currentNotes === '' || currentNotes === prevNotes || currentNotes === businessNotes;
        const footerUnchanged = currentFooter === '' || currentFooter === prevFooter || currentFooter === businessFooter;

        const nextDefaults = clientNotesFooterDefaults(client);

        if (notesUnchanged) {
            setFieldValue('notes', nextDefaults.notes);
        }
        // Footer is per-invoice (business default only); keep auto-fill when still on the shared default.
        if (footerUnchanged) {
            setFieldValue('footer', nextDefaults.footer);
        }

        previousClientDefaults.value = nextDefaults;
    },
);

const selectedClient = computed(() => {
    const clientId = formValues.value?.client_id;
    if (clientId == null) return null;
    return clientMap.value[Number(clientId)] ?? null;
});

const notesPrefilledFromClient = computed(() =>
    !props.isEditing
    && Boolean(selectedClient.value?.default_notes?.trim())
    && String(formValues.value.notes ?? '') === String(selectedClient.value?.default_notes ?? ''),
);

const documentDiscountCents = computed(() => {
    if (formValues.value.discount_type !== 'fixed') {
        return null;
    }
    return Math.round(Number(formValues.value.discount_amount || 0) * 100);
});

const totals = computed(() => {
    const lines = (formValues.value.line_items ?? []) as InvoiceLine[];
    const calc = calculateInvoiceTotals(
        lines.map((line) => ({
            quantity: Number(line.quantity) || 0,
            unit_price: line.unit_price,
            vat_rate: Number(line.vat_rate) || 0,
            discount_type: line.discount_type ?? null,
            discount_percent: line.discount_percent ?? null,
            discount_amount: line.discount_type === 'fixed' ? line.discount_amount : null,
        })),
        (formValues.value.discount_type as DiscountType) ?? null,
        formValues.value.discount_percent ?? null,
        documentDiscountCents.value,
    );

    const vatBreakdown = lines.reduce((acc, rawLine, index) => {
        const line = rawLine as InvoiceLine;
        const key = `${Math.round((line.vat_rate ?? 0) * 100)}%`;
        const vatAmount = calc.lines[index]?.vat_amount_cents ?? 0;
        acc[key] = (acc[key] ?? 0) + vatAmount;
        return acc;
    }, {} as Record<string, number>);

    const amountPaid = props.invoice?.amount_paid_cents ?? 0;
    const amountDue = Math.max(0, calc.total_cents - amountPaid);

    return {
        ...calc,
        subtotal: calc.subtotal_cents,
        vat: calc.vat_amount_cents,
        total: calc.total_cents,
        amountPaid,
        amountDue,
        vatBreakdown,
    };
});

const lineVat = (index: number) => totals.value.lines[index]?.vat_amount_cents ?? 0;
const lineTotal = (index: number) => totals.value.lines[index]?.total_cents ?? 0;

const displayCurrency = computed(() => (formValues.value.currency as string) || 'ZAR');
const formatCents = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, displayCurrency.value);

const catalogItems = computed(() => props.items ?? []);

const applyCatalogItem = (index: number, itemIdRaw: string) => {
    if (!itemIdRaw) {
        updateLine(index, 'item_id', null);
        return;
    }
    const itemId = Number(itemIdRaw);
    const item = catalogItems.value.find((entry) => entry.id === itemId);
    if (!item) {
        return;
    }
    const description = (item.description && item.description.trim() !== '')
        ? item.description
        : item.name;
    const vatRate = chargesVat.value
        ? (item.default_vat_rate ?? defaultVatRate.value)
        : 0;
    const next = (formValues.value.line_items ?? []).map((line: InvoiceLine, i: number) => (
        i === index
            ? {
                ...line,
                item_id: item.id,
                description,
                unit_price: (item.unit_price_cents / 100).toFixed(2),
                vat_rate: vatRate,
            }
            : line
    ));
    setFieldValue('line_items', next);
};

const addLine = () => {
    const next = [...(formValues.value.line_items ?? []), emptyLine()];
    setFieldValue('line_items', next);
};

const updateLine = (index: number, field: keyof InvoiceLine, value: any) => {
    const next = (formValues.value.line_items ?? []).map((line: InvoiceLine, i: number) => (
        i === index ? { ...line, [field]: value } : line
    ));
    setFieldValue('line_items', next);
};

const setLineDiscountType = (index: number, raw: string) => {
    const type: 'percent' | 'fixed' | null = raw === 'percent' || raw === 'fixed' ? raw : null;
    const next = (formValues.value.line_items ?? []).map((line: InvoiceLine, i: number) => {
        if (i !== index) return line;
        return {
            ...line,
            discount_type: type,
            discount_percent: type === 'percent' ? (line.discount_percent ?? 0) : null,
            discount_amount: type === 'fixed' ? (line.discount_amount ?? '0.00') : '0.00',
        };
    });
    setFieldValue('line_items', next);
};

const setDocumentDiscountType = (raw: string) => {
    const type: 'percent' | 'fixed' | null = raw === 'percent' || raw === 'fixed' ? raw : null;
    setFieldValue('discount_type', type);
    if (type !== 'percent') {
        setFieldValue('discount_percent', null);
    }
    if (type !== 'fixed') {
        setFieldValue('discount_amount', '0.00');
    }
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

const onLineDiscountAmountBlur = (index: number) => {
    const line = (formValues.value.line_items ?? [])[index] as InvoiceLine | undefined;
    if (!line) return;
    updateLine(index, 'discount_amount', normalizeMoneyInput(line.discount_amount));
};

const onDocumentDiscountAmountBlur = () => {
    setFieldValue('discount_amount', normalizeMoneyInput(formValues.value.discount_amount));
};

const removeLine = (index: number) => {
    if (!window.confirm('Remove this line item?')) {
        return;
    }
    const next = [...(formValues.value.line_items ?? [])];
    next.splice(index, 1);
    setFieldValue('line_items', next.length ? next : [emptyLine()]);
};

const buildDiscountPayload = (
    type: 'percent' | 'fixed' | null | undefined,
    percent: number | null | undefined,
    amount: string | number | null | undefined,
) => ({
    discount_type: type ?? null,
    discount_percent: type === 'percent' ? (percent ?? null) : null,
    discount_cents: type === 'fixed' ? Math.round(Number(amount || 0) * 100) : null,
});

const insertTemplate = (templateId: string) => {
    if (!templateId) return;
    const template = (props.note_templates ?? []).find((entry) => String(entry.id) === templateId);
    if (!template) return;
    const current = String(formValues.value.notes ?? '');
    const next = current.trim() ? `${current.trim()}\n\n${template.body}` : template.body;
    setFieldValue('notes', next);
};

const onSave = () => {
    if (saving.value) return;

    // Read `values` directly: nested line rows use v-model on shared objects; vee-validate's
    // handleSubmit() can pass a stale snapshot that omits those edits.
    const result = invoiceSchema.safeParse(formValues.value);
    if (!result.success) {
        setFromZod(result.error);
        return;
    }

    clear();

    const { line_items: lineItems, discount_amount: _docAmount, ...rest } = result.data;
    const payload = {
        ...rest,
        ...buildDiscountPayload(rest.discount_type, rest.discount_percent, formValues.value.discount_amount),
        income_account_id: rest.income_account_id ?? null,
        line_items: lineItems.map((line) => ({
            description: line.description,
            quantity: Number(line.quantity),
            unit_price_cents: Math.round(Number(line.unit_price) * 100),
            vat_rate: Number(line.vat_rate),
            item_id: line.item_id ?? null,
            income_account_id: line.income_account_id ?? null,
            ...buildDiscountPayload(line.discount_type, line.discount_percent, line.discount_amount),
        })),
    };

    const visitOptions = {
        onStart: () => {
            saving.value = true;
        },
        onSuccess: () => {
            toast.success(props.isEditing ? 'Invoice saved.' : 'Invoice created.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
            if (!Object.keys(errors).length) {
                toast.error('Could not save this invoice.');
            }
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (props.isEditing && props.invoice?.id) {
        router.put(route('invoicing.invoices.update', props.invoice.id), payload, visitOptions);
        return;
    }

    router.post(route('invoicing.invoices.store'), payload, visitOptions);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? `Edit ${invoice?.number}` : 'New Invoice'"
        :breadcrumbs="[
            { label: 'Money In', href: route('invoicing.invoices.index') },
            { label: 'Invoices', href: route('invoicing.invoices.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader :title="isEditing ? `Edit ${invoice?.number}` : 'Create Invoice'" />

        <FormValidationBanner
            class="mt-4"
            title="Could not save invoice"
            :errors="visibleValidationErrors"
        />

        <div class="mt-5 space-y-6">
                <AppCard class="border-slate-200/90 bg-slate-50">
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
                                <p v-if="fieldErrors.client_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.client_id }}</p>
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
                            <p v-if="fieldErrors.number" class="mt-1 text-xs text-rose-600">{{ fieldErrors.number }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Reference</label>
                            <AppInput :model-value="values.reference as string" @update:model-value="setFieldValue('reference', $event)" placeholder="PO number etc." />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Issue date</label>
                                <AppInput type="date" :model-value="values.issue_date as string" @update:model-value="setFieldValue('issue_date', $event)" />
                                <p v-if="fieldErrors.issue_date" class="mt-1 text-xs text-rose-600">{{ fieldErrors.issue_date }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Due date</label>
                                <AppInput type="date" :model-value="values.due_date as string" @update:model-value="setFieldValue('due_date', $event)" />
                                <p v-if="fieldErrors.due_date" class="mt-1 text-xs text-rose-600">{{ fieldErrors.due_date }}</p>
                            </div>
                        </div>
                        <div :class="accounts.length ? '' : 'md:col-span-2'">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Invoice currency</label>
                            <AppSelect
                                :model-value="values.currency as string"
                                :options="currencyOptions"
                                @update:model-value="setFieldValue('currency', $event)"
                            />
                            <p v-if="fieldErrors.currency" class="mt-1 text-xs text-rose-600">{{ fieldErrors.currency }}</p>
                            <p class="mt-1 text-xs text-slate-500">
                                Defaults to the selected client&rsquo;s currency; change here to override for this invoice only.
                            </p>
                        </div>
                        <div v-if="accounts.length">
                            <FieldHelp
                                label="Income account"
                                text="Applies to all lines unless a line overrides it."
                            />
                            <AppSelect
                                :model-value="values.income_account_id ? String(values.income_account_id) : ''"
                                :options="accountSelectOptions"
                                placeholder="Select income account"
                                @update:model-value="setFieldValue('income_account_id', $event ? Number($event) : null)"
                            />
                        </div>
                    </div>
                </AppCard>

                <AppCard>
                    <p v-if="!chargesVat" class="mb-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        VAT is not applied on this invoice. Enable VAT registered in Business settings to charge VAT (0% is zero-rated).
                    </p>
                    <h3 class="mb-3 text-base font-semibold text-slate-900">Line items</h3>
                    <p v-if="fieldErrors.line_items" class="mb-3 text-xs text-rose-600">{{ fieldErrors.line_items }}</p>

                    <div ref="lineItemsListRef" class="space-y-3">
                        <div
                            v-for="(line, index) in (values.line_items as InvoiceLine[])"
                            :key="line.row_key"
                            class="invoice-line-item rounded-lg border border-slate-200 bg-white p-3 shadow-sm"
                        >
                            <div class="flex gap-2">
                                <span
                                    class="line-drag-handle mt-5 inline-flex h-8 w-6 shrink-0 cursor-grab touch-manipulation items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-600 active:cursor-grabbing"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Drag to reorder line"
                                >
                                    <GripVertical class="h-4 w-4 shrink-0" />
                                </span>

                                <div class="min-w-0 flex-1 space-y-3">
                                    <div class="flex flex-wrap items-end gap-2">
                                        <div v-if="catalogItems.length" class="min-w-[12rem] flex-[2]">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Item</label>
                                            <AppSelect
                                                size="sm"
                                                :model-value="line.item_id ? String(line.item_id) : ''"
                                                :options="[
                                                    { label: 'Select item…', value: '' },
                                                    ...catalogItems.map((item) => ({
                                                        label: item.name,
                                                        value: String(item.id),
                                                    })),
                                                ]"
                                                placeholder="Select item…"
                                                @update:model-value="applyCatalogItem(index, String($event ?? ''))"
                                            />
                                        </div>
                                        <div v-else class="min-w-[12rem] flex-[2]">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Description</label>
                                            <AppInput
                                                class="!h-8 !px-2 !py-1 text-sm"
                                                :model-value="line.description"
                                                placeholder="What you delivered or sold"
                                                @update:model-value="updateLine(index, 'description', $event)"
                                            />
                                        </div>
                                        <div class="w-[4.5rem] shrink-0">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Qty</label>
                                            <AppInput
                                                class="!h-8 !px-2 !py-1 text-right text-sm tabular-nums"
                                                :model-value="line.quantity"
                                                type="number"
                                                inputmode="decimal"
                                                @update:model-value="updateLine(index, 'quantity', Number($event))"
                                            />
                                        </div>
                                        <div class="w-[6.5rem] shrink-0">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Unit price</label>
                                            <AppInput
                                                class="!h-8 !px-2 !py-1 text-right text-sm tabular-nums"
                                                :model-value="line.unit_price"
                                                type="text"
                                                inputmode="decimal"
                                                step="0.01"
                                                pattern="^\\d*(\\.\\d{0,2})?$"
                                                @update:model-value="updateLine(index, 'unit_price', $event)"
                                                @blur="onUnitPriceBlur(index)"
                                            />
                                        </div>
                                        <div v-if="chargesVat" class="w-[7.5rem] shrink-0">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">VAT</label>
                                            <AppSelect
                                                size="sm"
                                                :model-value="String(line.vat_rate)"
                                                :options="taxRateSelectOptions"
                                                @update:model-value="updateLine(index, 'vat_rate', Number($event))"
                                            />
                                        </div>
                                        <div v-if="chargesVat" class="w-[5rem] shrink-0">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">VAT amt</label>
                                            <div class="flex h-8 items-center justify-end text-xs tabular-nums text-slate-600">
                                                {{ formatCents(lineVat(index)) }}
                                            </div>
                                        </div>
                                        <div class="min-w-[5.5rem] shrink-0">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Total</label>
                                            <div class="flex h-8 items-center justify-end text-sm font-semibold tabular-nums text-slate-900">
                                                {{ formatCents(lineTotal(index)) }}
                                            </div>
                                        </div>
                                        <div class="shrink-0">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-transparent select-none" aria-hidden="true">Del</label>
                                            <button
                                                class="inline-flex h-8 w-8 items-center justify-center rounded text-rose-600 hover:bg-rose-50"
                                                type="button"
                                                :aria-label="`Remove line ${index + 1}`"
                                                @click="removeLine(index)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>

                                    <div v-if="catalogItems.length">
                                        <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Description</label>
                                        <textarea
                                            :value="line.description"
                                            rows="2"
                                            placeholder="What you delivered or sold"
                                            class="block w-full resize-y rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm leading-snug text-slate-900 outline-none ring-slate-300 transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                                            @input="updateLine(index, 'description', ($event.target as HTMLTextAreaElement).value)"
                                        />
                                    </div>

                                    <div class="flex flex-wrap items-end gap-2">
                                        <div class="min-w-[8rem] shrink-0">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Discount</label>
                                            <AppSelect
                                                size="sm"
                                                :model-value="line.discount_type ?? ''"
                                                :options="discountTypeOptions"
                                                @update:model-value="setLineDiscountType(index, String($event ?? ''))"
                                            />
                                        </div>
                                        <div v-if="line.discount_type === 'percent'" class="w-16 shrink-0">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Rate</label>
                                            <AppInput
                                                class="!h-8 !px-2 !py-1 text-right text-xs tabular-nums"
                                                :model-value="line.discount_percent ?? 0"
                                                type="number"
                                                inputmode="decimal"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                placeholder="%"
                                                @update:model-value="updateLine(index, 'discount_percent', Number($event))"
                                            />
                                        </div>
                                        <div v-if="line.discount_type === 'fixed'" class="w-20 shrink-0">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Amount</label>
                                            <AppInput
                                                class="!h-8 !px-2 !py-1 text-right text-xs tabular-nums"
                                                :model-value="line.discount_amount ?? '0.00'"
                                                type="text"
                                                inputmode="decimal"
                                                placeholder="0.00"
                                                @update:model-value="updateLine(index, 'discount_amount', $event)"
                                                @blur="onLineDiscountAmountBlur(index)"
                                            />
                                        </div>
                                        <div v-if="accounts.length" class="min-w-[10rem] max-w-[14rem] flex-1">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Income account</label>
                                            <AppSelect
                                                size="sm"
                                                :model-value="line.income_account_id ? String(line.income_account_id) : ''"
                                                :options="lineAccountSelectOptions"
                                                @update:model-value="updateLine(index, 'income_account_id', $event ? Number($event) : null)"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 flex justify-center border-t border-slate-200 pt-3">
                        <AppButton size="sm" variant="secondary" @click="addLine">
                            <Plus class="mr-1 h-4 w-4" />
                            Add line item
                        </AppButton>
                    </div>
                </AppCard>

                <AppCard class="border-slate-200/90 bg-slate-50">
                    <h3 class="mb-3 text-base font-semibold text-slate-900">Totals</h3>

                    <div class="mb-4 border-b border-slate-200 pb-4">
                        <p class="mb-2 text-xs font-medium text-slate-500">Document discount</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="min-w-[8rem]">
                                <AppSelect
                                    :model-value="(values.discount_type as string | null) ?? ''"
                                    :options="discountTypeOptions"
                                    @update:model-value="setDocumentDiscountType(String($event ?? ''))"
                                />
                            </div>
                            <AppInput
                                v-if="values.discount_type === 'percent'"
                                class="w-24 text-right tabular-nums"
                                :model-value="values.discount_percent ?? 0"
                                type="number"
                                inputmode="decimal"
                                min="0"
                                max="100"
                                step="0.01"
                                placeholder="%"
                                aria-label="Document discount percent"
                                @update:model-value="setFieldValue('discount_percent', Number($event))"
                            />
                            <AppInput
                                v-if="values.discount_type === 'fixed'"
                                class="w-28 text-right tabular-nums"
                                :model-value="values.discount_amount as string"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                                aria-label="Document discount amount"
                                @update:model-value="setFieldValue('discount_amount', $event)"
                                @blur="onDocumentDiscountAmountBlur"
                            />
                        </div>
                    </div>

                    <div class="space-y-2 text-sm text-slate-700">
                        <div class="flex items-center justify-between">
                            <span>{{ chargesVat ? 'Subtotal (excl VAT)' : 'Subtotal' }}</span>
                            <span>{{ formatCents(totals.subtotal) }}</span>
                        </div>
                        <div v-if="totals.discount_total_cents > 0" class="flex items-center justify-between text-rose-700">
                            <span>Discounts applied</span>
                            <span>&minus;{{ formatCents(totals.discount_total_cents) }}</span>
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
                            <FieldHelp
                                label="Notes"
                                text="Shown on the invoice. Use markdown and insert named templates from Settings (or attach defaults on the client)."
                            />
                            <MarkdownEditor
                                :model-value="String(values.notes ?? '')"
                                :rows="8"
                                placeholder="Payment instructions, banking details…"
                                aria-label="Invoice notes"
                                @update:model-value="setFieldValue('notes', $event)"
                            />
                            <div v-if="notesTemplateOptions.length" class="mt-4">
                                <AppSelect
                                    :model-value="''"
                                    :options="[{ label: 'Insert note template…', value: '' }, ...notesTemplateOptions]"
                                    @update:model-value="insertTemplate(String($event ?? ''))"
                                />
                            </div>
                            <p v-if="notesPrefilledFromClient" class="mt-1 text-xs text-slate-500">
                                Notes prefilled from this client&rsquo;s templates.
                            </p>
                        </div>
                        <div>
                            <FieldHelp
                                label="Footer"
                                text="Shown at the bottom of the invoice PDF. Freeform markdown for this invoice only (not from shared templates)."
                            />
                            <MarkdownEditor
                                :model-value="String(values.footer ?? '')"
                                :rows="8"
                                placeholder="Optional footer / terms for this invoice…"
                                aria-label="Invoice footer"
                                @update:model-value="setFieldValue('footer', $event)"
                            />
                        </div>
                    </div>
                </AppCard>
        </div>

        <FormActions sticky>
            <AppButton
                variant="primary"
                :disabled="!canSaveInvoice"
                :loading="saving"
                @click="onSave"
            >
                {{ saving ? 'Saving…' : 'Save' }}
            </AppButton>
            <AppButton variant="secondary" @click="router.visit(route('invoicing.invoices.index'))">
                Cancel
            </AppButton>
        </FormActions>
    </AppLayout>
</template>
