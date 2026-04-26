<script setup lang="ts">
import { computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import draggable from 'vuedraggable';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { GripVertical, Plus, Trash2, Upload } from 'lucide-vue-next';

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

const defaultVatRate = computed(() => props.tax_rates.find((rate) => rate.is_default)?.rate ?? 0.15);
const selectedFiles = computed<File[]>(() => values.value.attachments ?? []);
const makeRowKey = () => `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;

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
    attachments: z.array(z.any()).optional(),
});

const { handleSubmit, setErrors, values, setFieldValue } = useForm({
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
        attachments: [] as File[],
    },
});

const clientMap = computed<Record<number, ClientOption>>(() => (
    props.clients.reduce((acc, client) => {
        acc[client.id] = client;
        return acc;
    }, {} as Record<number, ClientOption>)
));

watch(
    () => [values.value.client_id, values.value.issue_date],
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
    const lines = values.value.line_items ?? [];
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
    const next = [...(values.value.line_items ?? []), {
        row_key: makeRowKey(),
        description: '',
        quantity: 1,
        unit_price: 0,
        vat_rate: defaultVatRate.value,
        account_id: null,
    }];
    setFieldValue('line_items', next);
};

const removeLine = (index: number) => {
    const next = [...(values.value.line_items ?? [])];
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

const handleAttachmentInput = (event: Event) => {
    const target = event.target as HTMLInputElement;
    const files = Array.from(target.files ?? []);
    setFieldValue('attachments', [...selectedFiles.value, ...files]);
};

const openPreview = () => {
    if (!props.isEditing || !props.invoice?.id) return;
    window.open(route('invoices.pdf.download', props.invoice.id), '_blank');
};

const onSubmit = (submitAction: 'draft' | 'send') => {
    handleSubmit((formValues) => {
        const result = invoiceSchema.safeParse(formValues);
        if (!result.success) {
            const mapped: Record<string, string> = {};
            for (const issue of result.error.issues) {
                mapped[issue.path.join('.')] = issue.message;
            }
            setErrors(mapped);
            return;
        }

        const payload = {
            ...result.data,
            submit_action: submitAction,
            line_items: result.data.line_items.map((line) => ({
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
    })();
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

        <div class="mt-5 grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <AppCard>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Client</label>
                            <AppSelect
                                :model-value="String(values.client_id ?? '')"
                                :options="clients.map((client) => ({ label: client.name, value: String(client.id) }))"
                                placeholder="Select client"
                                @update:model-value="setFieldValue('client_id', Number($event))"
                            />
                            <button class="mt-2 text-xs text-emerald-700 hover:underline" type="button">+ Add new client</button>
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
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-base font-semibold text-slate-900">Line items</h3>
                        <AppButton size="sm" variant="secondary" @click="addLine">
                            <Plus class="mr-1 h-4 w-4" />
                            Add line item
                        </AppButton>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-3 py-2 text-left"> </th>
                                    <th class="px-3 py-2 text-left">Description</th>
                                    <th class="px-3 py-2 text-left">Qty</th>
                                    <th class="px-3 py-2 text-left">Unit Price</th>
                                    <th class="px-3 py-2 text-left">VAT Rate</th>
                                    <th class="px-3 py-2 text-left">VAT Amount</th>
                                    <th class="px-3 py-2 text-left">Total</th>
                                    <th class="px-3 py-2 text-left">Delete</th>
                                </tr>
                            </thead>
                            <draggable
                                :model-value="values.line_items"
                                tag="tbody"
                                item-key="row_key"
                                handle=".drag-handle"
                                class="divide-y divide-slate-100"
                                @update:model-value="setFieldValue('line_items', $event)"
                            >
                                <template #item="{ element: line, index }">
                                    <tr>
                                        <td class="px-3 py-2 text-slate-400">
                                            <GripVertical class="drag-handle h-4 w-4 cursor-grab" />
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="space-y-2">
                                                <AppInput v-model="line.description" placeholder="Line description" />
                                                <AppSelect
                                                    v-if="accounts.length"
                                                    :model-value="line.account_id ? String(line.account_id) : ''"
                                                    :options="accounts.map((account) => ({ label: account.name, value: String(account.id) }))"
                                                    placeholder="Map income account"
                                                    @update:model-value="line.account_id = Number($event)"
                                                />
                                            </div>
                                        </td>
                                        <td class="px-3 py-2"><AppInput v-model="line.quantity" type="number" /></td>
                                        <td class="px-3 py-2"><AppInput v-model="line.unit_price" type="number" /></td>
                                        <td class="px-3 py-2">
                                            <AppSelect
                                                :model-value="String(line.vat_rate)"
                                                :options="tax_rates.map((rate) => ({ label: rate.name, value: String(rate.rate) }))"
                                                @update:model-value="line.vat_rate = Number($event)"
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
                                </template>
                            </draggable>
                        </table>
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
                            <label class="flex cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed border-slate-300 px-3 py-5 text-sm text-slate-500 hover:bg-slate-50">
                                <Upload class="h-4 w-4" />
                                Drag and drop or click to attach
                                <input type="file" multiple class="hidden" @change="handleAttachmentInput">
                            </label>
                            <ul v-if="selectedFiles.length" class="mt-2 space-y-1 text-xs text-slate-500">
                                <li v-for="file in selectedFiles" :key="file.name">{{ file.name }}</li>
                            </ul>
                        </div>
                        <AppButton :disabled="!isEditing" variant="secondary" @click="openPreview">Invoice preview (PDF)</AppButton>
                    </div>
                </AppCard>
            </div>
        </div>

        <div class="sticky bottom-0 mt-6 border-t border-slate-200 bg-white/95 px-2 py-3 backdrop-blur">
            <div class="flex items-center justify-end gap-2">
                <AppButton variant="ghost" @click="router.visit(route('invoicing.invoices.index'))">Cancel</AppButton>
                <AppButton variant="secondary" @click="onSubmit('draft')">Save as Draft</AppButton>
                <AppButton variant="primary" @click="onSubmit('send')">Save and Send</AppButton>
            </div>
        </div>
    </AppLayout>
</template>
