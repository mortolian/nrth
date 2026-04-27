<script setup lang="ts">
import { computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { Plus, Trash2 } from 'lucide-vue-next';

type ClientOption = { id: number; name: string; payment_terms_days: number };
type TaxRateOption = { id: number; name: string; rate: number; is_default: boolean };
type AccountOption = { id: number; name: string };
type InvoiceLine = {
    id?: number;
    row_key: string;
    description: string;
    quantity: number;
    unit_price: number;
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
        line_items: InvoiceLine[];
    };
    clients: ClientOption[];
    tax_rates: TaxRateOption[];
    accounts: AccountOption[];
    next_number: string;
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

const invoiceSchema = z.object({
    client_id: z.coerce.number().int().positive('Client is required'),
    number: z.string().min(1, 'Invoice number is required'),
    reference: z.string().optional(),
    issue_date: z.string().min(1, 'Issue date is required'),
    due_date: z.string().min(1, 'Due date is required'),
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
        client_id: props.invoice?.client_id ?? (props.clients[0]?.id ?? null),
        number: props.invoice?.number ?? props.next_number,
        reference: props.invoice?.reference ?? '',
        issue_date: props.invoice?.issue_date ?? new Date().toISOString().slice(0, 10),
        due_date: props.invoice?.due_date ?? new Date().toISOString().slice(0, 10),
        notes: props.invoice?.notes ?? props.defaults?.notes ?? '',
        footer: props.invoice?.footer ?? props.defaults?.footer ?? '',
        line_items: props.invoice?.line_items?.length
            ? props.invoice.line_items.map((line) => ({
                row_key: makeRowKey(),
                description: line.description,
                quantity: Number(line.quantity) || 1,
                unit_price: Number(line.unit_price) || 0,
                vat_rate: Number(line.vat_rate) || defaultVatRate.value,
                account_id: line.account_id ?? null,
            }))
            : [{ row_key: makeRowKey(), description: '', quantity: 1, unit_price: 0, vat_rate: defaultVatRate.value, account_id: null }],
    },
});

/**
 * vee-validate `values` can be returned as either a ref-like wrapper or a reactive object
 * depending on version/build. Normalize once so watcher getters never crash.
 */
const formValues = computed<Record<string, any>>(() => ((values as any)?.value ?? values) as Record<string, any>);

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

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const addLine = () => {
    const next = [...(formValues.value.line_items ?? []), {
        row_key: makeRowKey(),
        description: '',
        quantity: 1,
        unit_price: 0,
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

const removeLine = (index: number) => {
    const next = [...(formValues.value.line_items ?? [])];
    next.splice(index, 1);
    setFieldValue('line_items', next.length ? next : [{
        row_key: makeRowKey(),
        description: '',
        quantity: 1,
        unit_price: 0,
        vat_rate: defaultVatRate.value,
        account_id: null,
    }]);
};

const openPreview = () => {
    if (!props.isEditing || !props.invoice?.id) return;
    window.open(route('invoices.pdf.download', props.invoice.id), '_blank');
};

const onSubmit = (submitAction: 'draft' | 'send') => {
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
        submit_action: submitAction,
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
        <PageHeader
            :title="isEditing ? `Edit ${invoice?.number}` : 'Create Invoice'"
            subtitle="Create and manage invoice details, line items, and totals"
        />

        <div
            v-if="inertiaErrors.length"
            class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
        >
            <p class="font-medium">Could not save invoice</p>
            <ul class="mt-2 list-inside list-disc space-y-1 text-rose-800">
                <li v-for="err in inertiaErrors" :key="err.key">{{ err.message }}</li>
            </ul>
        </div>

        <div class="mt-5 grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
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
                    </div>
                </AppCard>

                <AppCard>
                    <p v-if="!chargesVat" class="mb-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        VAT is not applied on this invoice. Enable VAT registered and choose a default VAT rate in Company settings to charge VAT.
                    </p>
                    <h3 class="mb-3 text-base font-semibold text-slate-900">Line items</h3>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">Description</th>
                                    <th class="px-3 py-2 text-left">Qty</th>
                                    <th class="px-3 py-2 text-left">Unit Price</th>
                                    <th class="px-3 py-2 text-left">VAT Rate</th>
                                    <th class="px-3 py-2 text-left">VAT Amount</th>
                                    <th class="px-3 py-2 text-left">Total</th>
                                    <th class="px-3 py-2 text-left">Delete</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(line, index) in (values.line_items as InvoiceLine[])" :key="line.row_key">
                                    <td class="px-3 py-2">
                                        <div class="space-y-2">
                                            <AppInput
                                                :model-value="line.description"
                                                placeholder="Line description"
                                                @update:model-value="updateLine(index, 'description', $event)"
                                            />
                                            <AppSelect
                                                v-if="accounts.length"
                                                :model-value="line.account_id ? String(line.account_id) : ''"
                                                :options="accounts.map((account) => ({ label: account.name, value: String(account.id) }))"
                                                placeholder="Map income account"
                                                @update:model-value="updateLine(index, 'account_id', Number($event))"
                                            />
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <AppInput
                                            :model-value="line.quantity"
                                            type="number"
                                            @update:model-value="updateLine(index, 'quantity', Number($event))"
                                        />
                                    </td>
                                    <td class="px-3 py-2">
                                        <AppInput
                                            :model-value="line.unit_price"
                                            type="number"
                                            @update:model-value="updateLine(index, 'unit_price', Number($event))"
                                        />
                                    </td>
                                    <td class="px-3 py-2">
                                        <AppSelect
                                            :model-value="String(line.vat_rate)"
                                            :options="taxRateSelectOptions"
                                            :disabled="!chargesVat"
                                            @update:model-value="updateLine(index, 'vat_rate', Number($event))"
                                        />
                                    </td>
                                    <td class="px-3 py-2">{{ formatCents(lineVat(line)) }}</td>
                                    <td class="px-3 py-2 font-medium">{{ formatCents(lineTotal(line)) }}</td>
                                    <td class="px-3 py-2">
                                        <button class="rounded p-1 text-rose-600 hover:bg-rose-50" type="button" @click="removeLine(index)">
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
                        <div class="flex items-center justify-between"><span>Subtotal (excl VAT)</span><span>{{ formatCents(totals.subtotal) }}</span></div>
                        <div v-for="(amount, key) in totals.vatBreakdown" :key="key" class="flex items-center justify-between">
                            <span>VAT {{ key }}</span>
                            <span>{{ formatCents(amount) }}</span>
                        </div>
                        <div class="flex items-center justify-between border-t border-slate-200 pt-2 font-semibold"><span>Total (incl VAT)</span><span>{{ formatCents(totals.total) }}</span></div>
                        <div v-if="isEditing" class="flex items-center justify-between"><span>Amount paid</span><span>{{ formatCents(totals.amountPaid) }}</span></div>
                        <div class="flex items-center justify-between text-base font-bold text-slate-900"><span>Amount due</span><span>{{ formatCents(totals.amountDue) }}</span></div>
                    </div>
                </AppCard>
            </div>

            <div class="space-y-6">
                <AppCard>
                    <h3 class="mb-3 text-base font-semibold text-slate-900">Details</h3>
                    <div class="space-y-3">
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
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Attachments</label>
                            <p class="text-xs leading-relaxed text-slate-500">
                                PDFs are generated when you download or send an invoice from its detail page. Uploading extra files here is not supported yet.
                            </p>
                        </div>
                        <AppButton :disabled="!isEditing" variant="secondary" @click="openPreview">Invoice preview (PDF)</AppButton>
                    </div>
                </AppCard>
            </div>
        </div>

        <div class="sticky bottom-0 mt-6 border-t border-slate-200 bg-white/95 px-2 py-3 backdrop-blur">
            <div class="flex items-center justify-end gap-2">
                <AppButton variant="ghost" @click="router.visit(route('invoicing.invoices.index'))">Cancel</AppButton>
                <AppButton variant="secondary" :disabled="!canSaveInvoice" @click="onSubmit('draft')">Save as Draft</AppButton>
                <AppButton variant="primary" :disabled="!canSaveInvoice" @click="onSubmit('send')">Save and Send</AppButton>
            </div>
        </div>
    </AppLayout>
</template>
