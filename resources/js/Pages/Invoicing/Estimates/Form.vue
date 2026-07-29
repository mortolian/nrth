<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Sortable from 'sortablejs';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppCard from '@/Components/AppCard.vue';
import FormValidationBanner from '@/Components/FormValidationBanner.vue';
import MarkdownEditor from '@/Components/MarkdownEditor.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { calculateInvoiceTotals, type DiscountType } from '@/Composables/useInvoiceTotals';
import { useToast } from '@/Composables/useToast';
import { GripVertical, Plus, Trash2 } from 'lucide-vue-next';
import { z } from 'zod';

type ClientOption = {
    id: number;
    name: string;
    currency: string;
    default_notes?: string;
};
type NoteTemplateOption = { id: number; name: string; body: string; target: 'notes' | 'footer' };
type TaxRateOption = { id: number; name: string; rate: number; is_default: boolean };
type CatalogItemOption = {
    id: number;
    name: string;
    description: string | null;
    unit: string | null;
    unit_price_cents: number;
    default_vat_rate: number | null;
};
type EstimateLineApi = {
    description: string;
    quantity: number;
    unit_price_cents: number;
    vat_rate: number;
    item_id?: number | null;
    discount_type?: DiscountType;
    discount_percent?: number | null;
    discount_cents?: number | null;
};
type EstimateLineForm = {
    row_key: string;
    item_id?: number | null;
    description: string;
    quantity: number;
    /** Major units (e.g. rands) for inputs; converted to cents on save. */
    unit_price: string;
    vat_rate: number;
    discount_type: DiscountType;
    discount_percent: number | null;
    /** Major units for fixed line discount; converted to cents on save. */
    discount_amount: string;
};
type EstimatePayload = {
    id: number;
    client_id: number;
    number: string;
    issue_date: string;
    expiry_date: string;
    currency: string;
    notes: string | null;
    terms: string | null;
    discount_type?: DiscountType;
    discount_percent?: number | null;
    discount_cents?: number | null;
    discount_total_cents?: number;
    line_items: EstimateLineApi[];
};

const props = defineProps<{
    isEditing: boolean;
    estimate: EstimatePayload | null;
    clients: ClientOption[];
    items?: CatalogItemOption[];
    tax_rates: TaxRateOption[];
    charges_vat: boolean;
    next_number: string;
    default_currency: string;
    /** Business settings: used when creating a new estimate only */
    default_notes?: string;
    default_terms?: string;
    note_templates?: NoteTemplateOption[];
}>();

const page = usePage();
const currencyOptions = computed(
    () => (page.props.currencyOptions as Array<{ value: string; label: string }>) ?? [],
);

const chargesVat = computed(() => props.charges_vat);

const clientNotesTermsDefaults = (client: ClientOption | undefined) => ({
    notes: (client?.default_notes?.trim() ? client.default_notes : props.default_notes) ?? '',
    terms: props.default_terms ?? '',
});

const notesTemplateOptions = computed(() =>
    (props.note_templates ?? [])
        .filter((template) => template.target === 'notes')
        .map((template) => ({ label: template.name, value: String(template.id) })),
);

const defaultLineVat = computed(() => {
    if (!chargesVat.value) {
        return 0;
    }
    const def = props.tax_rates.find((r) => r.is_default);
    if (def) {
        return def.rate;
    }
    return props.tax_rates[0]?.rate ?? 0;
});

const vatSelectOptions = computed(() => {
    if (!chargesVat.value) {
        return [{ label: 'No VAT', value: '0' }];
    }
    if (props.tax_rates.length) {
        return props.tax_rates.map((r) => ({ label: `${r.name} (${(r.rate * 100).toFixed(0)}%)`, value: String(r.rate) }));
    }
    return [{ label: 'No VAT', value: '0' }];
});

const initialEstimateClientId = props.estimate?.client_id ?? props.clients[0]?.id ?? 0;
const initialEstimateCurrency =
    props.estimate?.currency
    ?? props.clients.find((c) => c.id === initialEstimateClientId)?.currency
    ?? props.default_currency
    ?? 'ZAR';

const initialClientDefaults = clientNotesTermsDefaults(
    props.clients.find((c) => c.id === initialEstimateClientId),
);

const form = ref({
    client_id: initialEstimateClientId,
    number: props.estimate?.number ?? props.next_number,
    issue_date: props.estimate?.issue_date ?? new Date().toISOString().slice(0, 10),
    expiry_date: props.estimate?.expiry_date ?? new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10),
    currency: initialEstimateCurrency,
    notes: props.estimate?.notes ?? initialClientDefaults.notes,
    terms: props.estimate?.terms ?? initialClientDefaults.terms,
    discount_type: (props.estimate?.discount_type ?? null) as DiscountType,
    discount_percent: props.estimate?.discount_percent ?? null,
    discount_amount: props.estimate?.discount_cents != null
        ? (props.estimate.discount_cents / 100).toFixed(2)
        : '0.00',
});

const previousClientDefaults = ref({ ...initialClientDefaults });

const discountTypeOptions = [
    { label: 'No discount', value: '' },
    { label: 'Percent %', value: 'percent' },
    { label: 'Fixed amount', value: 'fixed' },
];

const emptyLineDiscount = () => ({
    discount_type: null as DiscountType,
    discount_percent: null as number | null,
    discount_amount: '0.00',
});

const saving = ref(false);
const toast = useToast();
const { fieldErrors, setFromZod, setFromServer, clear, clearField, messages: clientErrorMessages } = useFieldErrors();
const makeRowKey = () => `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;

const mapApiLineToForm = (row: EstimateLineApi): EstimateLineForm => ({
    row_key: makeRowKey(),
    item_id: row.item_id ?? null,
    description: row.description ?? '',
    quantity: Number(row.quantity) || 1,
    unit_price: (((Number(row.unit_price_cents) || 0) / 100)).toFixed(2),
    vat_rate: Number(row.vat_rate) || 0,
    discount_type: row.discount_type ?? null,
    discount_percent: row.discount_percent ?? null,
    discount_amount: row.discount_cents != null
        ? (row.discount_cents / 100).toFixed(2)
        : '0.00',
});

const estimateSchema = z.object({
    client_id: z.coerce.number().int().positive('Select a client'),
    number: z.string().trim().min(1, 'Estimate number is required'),
    issue_date: z.string().min(1, 'Issue date is required'),
    expiry_date: z.string().min(1, 'Expiry date is required'),
    currency: z
        .string()
        .length(3, 'Select a currency')
        .regex(/^[A-Z]{3}$/, 'Use a 3-letter ISO currency code'),
    notes: z.string().optional(),
    terms: z.string().optional(),
    line_items: z.array(z.object({
        description: z.string().min(1, 'Description is required'),
        quantity: z.coerce.number().positive('Qty must be greater than 0'),
        unit_price: z.coerce.number().min(0, 'Unit price cannot be negative'),
        vat_rate: z.coerce.number().min(0).max(1),
        item_id: z.coerce.number().nullable().optional(),
    })).min(1, 'Add at least one line item'),
});

const catalogItems = computed(() => props.items ?? []);

const lineItems = ref<EstimateLineForm[]>(
    props.estimate?.line_items?.length
        ? props.estimate.line_items.map((row) => mapApiLineToForm(row))
        : [{
            row_key: makeRowKey(),
            item_id: null,
            description: '',
            quantity: 1,
            unit_price: '0.00',
            vat_rate: defaultLineVat.value,
            ...emptyLineDiscount(),
        }],
);

const lineItemsListRef = ref<HTMLElement | null>(null);
let lineItemSortable: ReturnType<typeof Sortable.create> | null = null;

const lineItemsOrderSignature = computed(() => lineItems.value.map((l) => l.row_key).join('|'));

const initLineItemSortable = () => {
    lineItemSortable?.destroy();
    lineItemSortable = null;
    const el = lineItemsListRef.value;
    if (!el || el.querySelectorAll('.estimate-line-item').length === 0) {
        return;
    }
    lineItemSortable = Sortable.create(el, {
        animation: 150,
        handle: '.estimate-line-drag-handle',
        draggable: '.estimate-line-item',
        onEnd(evt) {
            const { oldIndex, newIndex } = evt;
            if (oldIndex === undefined || newIndex === undefined || oldIndex === newIndex) {
                return;
            }
            const lines = [...lineItems.value];
            const [moved] = lines.splice(oldIndex, 1);
            lines.splice(newIndex, 0, moved);
            lineItems.value = lines;
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

watch(
    chargesVat,
    (on) => {
        if (!on) {
            lineItems.value = lineItems.value.map((row) => ({ ...row, vat_rate: 0 }));
        }
    },
    { immediate: true },
);

watch(
    () => form.value.client_id,
    (clientId) => {
        const c = props.clients.find((x) => x.id === clientId);
        if (c?.currency) {
            form.value.currency = c.currency;
        }
    },
);

watch(
    () => form.value.client_id,
    (clientId) => {
        if (props.isEditing || !clientId) return;
        const client = props.clients.find((x) => x.id === clientId);
        if (!client) return;

        const businessNotes = props.default_notes ?? '';
        const businessTerms = props.default_terms ?? '';
        const currentNotes = String(form.value.notes ?? '');
        const currentTerms = String(form.value.terms ?? '');
        const prevNotes = previousClientDefaults.value.notes;
        const prevTerms = previousClientDefaults.value.terms;

        const notesUnchanged = currentNotes === '' || currentNotes === prevNotes || currentNotes === businessNotes;
        const termsUnchanged = currentTerms === '' || currentTerms === prevTerms || currentTerms === businessTerms;

        const nextDefaults = clientNotesTermsDefaults(client);

        if (notesUnchanged) {
            form.value.notes = nextDefaults.notes;
        }
        if (termsUnchanged) {
            form.value.terms = nextDefaults.terms;
        }

        previousClientDefaults.value = nextDefaults;
    },
);

const insertTemplate = (templateId: string) => {
    if (!templateId) return;
    const template = (props.note_templates ?? []).find((entry) => String(entry.id) === templateId);
    if (!template) return;
    const current = String(form.value.notes ?? '');
    form.value.notes = current.trim() ? `${current.trim()}\n\n${template.body}` : template.body;
};

const documentDiscountCents = computed(() => {
    if (form.value.discount_type !== 'fixed') {
        return null;
    }
    return Math.round(Number(form.value.discount_amount || 0) * 100);
});

const totals = computed(() => calculateInvoiceTotals(
    lineItems.value.map((line) => ({
        quantity: Number(line.quantity) || 0,
        unit_price: line.unit_price,
        vat_rate: Number(line.vat_rate) || 0,
        discount_type: line.discount_type,
        discount_percent: line.discount_percent,
        discount_amount: line.discount_amount,
    })),
    form.value.discount_type,
    form.value.discount_type === 'percent' ? form.value.discount_percent : null,
    documentDiscountCents.value,
));

const vatBreakdown = computed(() => {
    const breakdown: Record<string, number> = {};
    lineItems.value.forEach((line, index) => {
        const vatCents = totals.value.lines[index]?.vat_amount_cents ?? 0;
        if (vatCents <= 0) {
            return;
        }
        const key = `${Math.round((Number(line.vat_rate) || 0) * 100)}%`;
        breakdown[key] = (breakdown[key] ?? 0) + vatCents;
    });
    return breakdown;
});

const money = (cents: number) => useFormatCurrency(cents / 100, form.value.currency || 'ZAR');

const onDiscountAmountBlur = (index: number | 'document') => {
    if (index === 'document') {
        form.value.discount_amount = normalizeMoneyInput(form.value.discount_amount);
        return;
    }
    const line = lineItems.value[index];
    if (!line) {
        return;
    }
    updateLine(index, 'discount_amount', normalizeMoneyInput(line.discount_amount));
};

const setLineDiscountType = (index: number, raw: string) => {
    const discountType = (raw === '' ? null : raw) as DiscountType;
    lineItems.value = lineItems.value.map((line, i) => (
        i === index
            ? {
                ...line,
                discount_type: discountType,
                discount_percent: discountType === 'percent' ? (line.discount_percent ?? 0) : null,
                discount_amount: discountType === 'fixed' ? line.discount_amount : '0.00',
            }
            : line
    ));
};

const setDocumentDiscountType = (raw: string) => {
    const discountType = (raw === '' ? null : raw) as DiscountType;
    form.value.discount_type = discountType;
    if (discountType !== 'percent') {
        form.value.discount_percent = null;
    }
    if (discountType !== 'fixed') {
        form.value.discount_amount = '0.00';
    }
};
const updateLine = (index: number, field: keyof EstimateLineForm, value: string | number) => {
    if (field === 'row_key') {
        return;
    }
    lineItems.value = lineItems.value.map((line, i) => (i === index ? { ...line, [field]: value } : line));
};

const normalizeMoneyInput = (raw: unknown): string => {
    const cleaned = String(raw ?? '').trim().replace(',', '.');
    if (cleaned === '') return '0.00';
    const parsed = Number(cleaned);
    if (!Number.isFinite(parsed) || parsed < 0) return '0.00';
    return parsed.toFixed(2);
};

const onUnitPriceBlur = (index: number) => {
    const line = lineItems.value[index];
    if (!line) return;
    updateLine(index, 'unit_price', normalizeMoneyInput(line.unit_price));
};

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
        ? (item.default_vat_rate ?? defaultLineVat.value)
        : 0;
    lineItems.value = lineItems.value.map((line, i) => (
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
};

const addLine = () => {
    lineItems.value = [...lineItems.value, {
        row_key: makeRowKey(),
        item_id: null,
        description: '',
        quantity: 1,
        unit_price: '0.00',
        vat_rate: defaultLineVat.value,
        ...emptyLineDiscount(),
    }];
};

const removeLine = (index: number) => {
    if (!window.confirm('Remove this line item?')) {
        return;
    }
    const next = [...lineItems.value];
    next.splice(index, 1);
    lineItems.value = next.length ? next : [{
        row_key: makeRowKey(),
        item_id: null,
        description: '',
        quantity: 1,
        unit_price: '0.00',
        vat_rate: defaultLineVat.value,
        ...emptyLineDiscount(),
    }];
};

const submit = (submitAction: 'draft' | 'send') => {
    if (saving.value) return;

    const result = estimateSchema.safeParse({
        ...form.value,
        line_items: lineItems.value,
    });
    if (!result.success) {
        setFromZod(result.error);
        return;
    }

    clear();

    const payload = {
        ...result.data,
        submit_action: submitAction,
        discount_type: form.value.discount_type ?? null,
        discount_percent: form.value.discount_type === 'percent' ? form.value.discount_percent : null,
        discount_cents: form.value.discount_type === 'fixed'
            ? Math.round(Number(form.value.discount_amount || 0) * 100)
            : null,
        line_items: result.data.line_items.map((line, index) => {
            const source = lineItems.value[index];
            return {
                description: line.description,
                quantity: Number(line.quantity),
                unit_price_cents: Math.round(Number(line.unit_price) * 100),
                vat_rate: Number(line.vat_rate),
                item_id: line.item_id ?? null,
                discount_type: source?.discount_type ?? null,
                discount_percent: source?.discount_type === 'percent' ? source.discount_percent : null,
                discount_cents: source?.discount_type === 'fixed'
                    ? Math.round(Number(source.discount_amount || 0) * 100)
                    : null,
            };
        }),
    };

    const visitOptions = {
        onStart: () => {
            saving.value = true;
        },
        onSuccess: () => {
            toast.success(props.isEditing ? 'Estimate saved.' : 'Estimate created.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
            if (!Object.keys(errors).length) {
                toast.error('Could not save this estimate.');
            }
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (props.isEditing && props.estimate?.id) {
        router.put(route('invoicing.estimates.update', props.estimate.id), payload, visitOptions);
        return;
    }
    router.post(route('invoicing.estimates.store'), payload, visitOptions);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit Estimate' : 'Create Estimate'"
        :breadcrumbs="[
            { label: 'Money In', href: route('invoicing.invoices.index') },
            { label: 'Estimates', href: route('invoicing.estimates.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <Head :title="isEditing ? 'Edit Estimate' : 'Create Estimate'" />
        <PageHeader :title="isEditing ? `Edit ${form.number}` : 'Create Estimate'" />

        <FormValidationBanner
            class="mt-4"
            title="Could not save estimate"
            :errors="clientErrorMessages"
        />

        <div class="mt-5 space-y-6">
                <AppCard class="border-slate-200/90 bg-slate-50">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Client</label>
                            <AppSelect
                                :model-value="String(form.client_id)"
                                :options="clients.map((c) => ({ label: c.name, value: String(c.id) }))"
                                placeholder="Select client"
                                @update:model-value="form.client_id = Number($event); clearField('client_id')"
                            />
                            <p v-if="fieldErrors.client_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.client_id }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Estimate number</label>
                            <AppInput
                                :model-value="form.number"
                                @update:model-value="(v) => { form.number = v; clearField('number'); }"
                            />
                            <p v-if="fieldErrors.number" class="mt-1 text-xs text-rose-600">{{ fieldErrors.number }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Issue date</label>
                            <AppInput
                                :model-value="form.issue_date"
                                type="date"
                                @update:model-value="(v) => { form.issue_date = v; clearField('issue_date'); }"
                            />
                            <p v-if="fieldErrors.issue_date" class="mt-1 text-xs text-rose-600">{{ fieldErrors.issue_date }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Expiry date</label>
                            <AppInput
                                :model-value="form.expiry_date"
                                type="date"
                                @update:model-value="(v) => { form.expiry_date = v; clearField('expiry_date'); }"
                            />
                            <p v-if="fieldErrors.expiry_date" class="mt-1 text-xs text-rose-600">{{ fieldErrors.expiry_date }}</p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Estimate currency</label>
                            <AppSelect
                                :model-value="form.currency"
                                :options="currencyOptions"
                                @update:model-value="form.currency = $event"
                            />
                            <p class="mt-1 text-xs text-slate-500">
                                Defaults to the client&rsquo;s currency; change to override for this estimate only.
                            </p>
                        </div>
                    </div>
                </AppCard>

                <AppCard>
                    <p v-if="!chargesVat" class="mb-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        VAT is not applied on this estimate. Enable VAT registered and choose a default VAT rate in Business settings to charge VAT.
                    </p>
                    <h3 class="mb-3 text-base font-semibold text-slate-900">Line items</h3>
                    <p v-if="fieldErrors.line_items" class="mb-3 text-xs text-rose-600">{{ fieldErrors.line_items }}</p>

                    <div ref="lineItemsListRef" class="space-y-3">
                        <div
                            v-for="(line, index) in lineItems"
                            :key="line.row_key"
                            class="estimate-line-item rounded-lg border border-slate-200 bg-white p-3 shadow-sm"
                        >
                            <div class="flex gap-2">
                                <span
                                    class="estimate-line-drag-handle mt-5 inline-flex h-8 w-6 shrink-0 cursor-grab touch-manipulation items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-600 active:cursor-grabbing"
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
                                                :options="vatSelectOptions"
                                                @update:model-value="updateLine(index, 'vat_rate', Number($event))"
                                            />
                                        </div>
                                        <div v-if="chargesVat" class="w-[5rem] shrink-0">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">VAT amt</label>
                                            <div class="flex h-8 items-center justify-end text-xs tabular-nums text-slate-600">
                                                {{ money(totals.lines[index]?.vat_amount_cents ?? 0) }}
                                            </div>
                                        </div>
                                        <div class="min-w-[5.5rem] shrink-0">
                                            <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Total</label>
                                            <div class="flex h-8 items-center justify-end text-sm font-semibold tabular-nums text-slate-900">
                                                {{ money(totals.lines[index]?.total_cents ?? 0) }}
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

                                    <div>
                                        <label class="mb-0.5 block text-[10px] font-medium uppercase tracking-wide text-slate-400">Description</label>
                                        <textarea
                                            :value="line.description"
                                            rows="2"
                                            placeholder="Line item description"
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
                                                @blur="onDiscountAmountBlur(index)"
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
                                    :model-value="form.discount_type ?? ''"
                                    :options="discountTypeOptions"
                                    @update:model-value="setDocumentDiscountType(String($event ?? ''))"
                                />
                            </div>
                            <AppInput
                                v-if="form.discount_type === 'percent'"
                                class="w-24 text-right tabular-nums"
                                :model-value="form.discount_percent ?? 0"
                                type="number"
                                inputmode="decimal"
                                min="0"
                                max="100"
                                step="0.01"
                                placeholder="%"
                                aria-label="Document discount percent"
                                @update:model-value="form.discount_percent = Number($event)"
                            />
                            <AppInput
                                v-if="form.discount_type === 'fixed'"
                                class="w-28 text-right tabular-nums"
                                :model-value="form.discount_amount"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                                aria-label="Document discount amount"
                                @update:model-value="form.discount_amount = String($event)"
                                @blur="onDiscountAmountBlur('document')"
                            />
                        </div>
                    </div>

                    <div class="space-y-2 text-sm text-slate-700">
                        <div class="flex items-center justify-between">
                            <span>{{ chargesVat ? 'Subtotal (excl VAT)' : 'Subtotal' }}</span>
                            <span>{{ money(totals.subtotal_cents) }}</span>
                        </div>
                        <div
                            v-if="totals.discount_total_cents > 0"
                            class="flex items-center justify-between text-rose-700"
                        >
                            <span>Discounts applied</span>
                            <span>&minus;{{ money(totals.discount_total_cents) }}</span>
                        </div>
                        <template v-if="chargesVat">
                            <div
                                v-for="(amount, key) in vatBreakdown"
                                :key="key"
                                class="flex items-center justify-between"
                            >
                                <span>VAT {{ key }}</span>
                                <span>{{ money(amount) }}</span>
                            </div>
                        </template>
                        <div class="flex items-center justify-between border-t border-slate-200 pt-2 font-semibold">
                            <span>{{ chargesVat ? 'Total (incl VAT)' : 'Total' }}</span>
                            <span>{{ money(totals.total_cents) }}</span>
                        </div>
                    </div>
                </AppCard>

                <AppCard>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                    <MarkdownEditor
                        v-model="form.notes"
                        :rows="6"
                        placeholder="Payment instructions, banking details…"
                        aria-label="Estimate notes"
                    />
                    <div v-if="notesTemplateOptions.length" class="mt-4">
                        <AppSelect
                            :model-value="''"
                            :options="[{ label: 'Insert note template…', value: '' }, ...notesTemplateOptions]"
                            @update:model-value="insertTemplate(String($event ?? ''))"
                        />
                    </div>
                    <label class="mb-1 mt-3 block text-xs font-medium text-slate-500">Terms</label>
                    <MarkdownEditor
                        v-model="form.terms"
                        :rows="6"
                        placeholder="Optional terms for this estimate…"
                        aria-label="Estimate terms"
                    />
                </AppCard>
        </div>

        <FormActions sticky>
            <AppButton variant="primary" :loading="saving" @click="submit('draft')">
                {{ saving ? 'Saving…' : 'Save' }}
            </AppButton>
            <AppButton variant="secondary" @click="router.visit(route('invoicing.estimates.index'))">
                Cancel
            </AppButton>
        </FormActions>
    </AppLayout>
</template>
