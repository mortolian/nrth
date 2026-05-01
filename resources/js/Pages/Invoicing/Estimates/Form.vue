<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Sortable from 'sortablejs';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppCard from '@/Components/AppCard.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { GripVertical, Plus, Trash2 } from 'lucide-vue-next';

type ClientOption = { id: number; name: string; currency: string };
type TaxRateOption = { id: number; name: string; rate: number; is_default: boolean };
type EstimateLineApi = { description: string; quantity: number; unit_price_cents: number; vat_rate: number };
type EstimateLineForm = {
    row_key: string;
    description: string;
    quantity: number;
    /** Major units (e.g. rands) for inputs; converted to cents on save. */
    unit_price: string;
    vat_rate: number;
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
    line_items: EstimateLineApi[];
};

const props = defineProps<{
    isEditing: boolean;
    estimate: EstimatePayload | null;
    clients: ClientOption[];
    tax_rates: TaxRateOption[];
    charges_vat: boolean;
    next_number: string;
    default_currency: string;
    /** Company settings: used when creating a new estimate only */
    default_notes?: string;
    default_terms?: string;
}>();

const page = usePage();
const currencyOptions = computed(
    () => (page.props.currencyOptions as Array<{ value: string; label: string }>) ?? [],
);

const chargesVat = computed(() => props.charges_vat);

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

const form = ref({
    client_id: initialEstimateClientId,
    number: props.estimate?.number ?? props.next_number,
    issue_date: props.estimate?.issue_date ?? new Date().toISOString().slice(0, 10),
    expiry_date: props.estimate?.expiry_date ?? new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10),
    currency: initialEstimateCurrency,
    notes: props.estimate?.notes ?? (props.default_notes ?? ''),
    terms: props.estimate?.terms ?? (props.default_terms ?? ''),
});

const makeRowKey = () => `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;

const lineItems = ref<EstimateLineForm[]>(
    props.estimate?.line_items?.length
        ? props.estimate.line_items.map((row) => ({
            row_key: makeRowKey(),
            description: row.description ?? '',
            quantity: Number(row.quantity) || 1,
            unit_price: (((Number(row.unit_price_cents) || 0) / 100)).toFixed(2),
            vat_rate: Number(row.vat_rate) || 0,
        }))
        : [{
            row_key: makeRowKey(),
            description: '',
            quantity: 1,
            unit_price: '0.00',
            vat_rate: defaultLineVat.value,
        }],
);

const lineItemsTbodyRef = ref<HTMLTableSectionElement | null>(null);
let lineItemSortable: ReturnType<typeof Sortable.create> | null = null;

const lineItemsOrderSignature = computed(() => lineItems.value.map((l) => l.row_key).join('|'));

const initLineItemSortable = () => {
    lineItemSortable?.destroy();
    lineItemSortable = null;
    const el = lineItemsTbodyRef.value;
    if (!el || el.querySelectorAll('tr').length === 0) {
        return;
    }
    lineItemSortable = Sortable.create(el, {
        animation: 150,
        handle: '.estimate-line-drag-handle',
        draggable: 'tr',
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

const lineSubtotalCents = (row: EstimateLineForm) =>
    Math.round((Number(row.quantity) || 0) * (Number(row.unit_price) || 0) * 100);

const lineVatCents = (row: EstimateLineForm) => Math.round(lineSubtotalCents(row) * (Number(row.vat_rate) || 0));

const lineTotalCents = (row: EstimateLineForm) => lineSubtotalCents(row) + lineVatCents(row);

const totals = computed(() => {
    const subtotal = lineItems.value.reduce((acc, row) => acc + lineSubtotalCents(row), 0);
    const vat = lineItems.value.reduce((acc, row) => acc + lineVatCents(row), 0);
    return { subtotal, vat, total: subtotal + vat };
});

const money = (cents: number) => useFormatCurrency(cents / 100, form.value.currency || 'ZAR');

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

const addLine = () => {
    lineItems.value = [...lineItems.value, {
        row_key: makeRowKey(),
        description: '',
        quantity: 1,
        unit_price: '0.00',
        vat_rate: defaultLineVat.value,
    }];
};

const removeLine = (index: number) => {
    const next = [...lineItems.value];
    next.splice(index, 1);
    lineItems.value = next.length ? next : [{
        row_key: makeRowKey(),
        description: '',
        quantity: 1,
        unit_price: '0.00',
        vat_rate: defaultLineVat.value,
    }];
};

const submit = (submitAction: 'draft' | 'send') => {
    const payload = {
        ...form.value,
        submit_action: submitAction,
        line_items: lineItems.value.map((line) => ({
            description: line.description,
            quantity: Number(line.quantity),
            unit_price_cents: Math.round(Number(line.unit_price) * 100),
            vat_rate: Number(line.vat_rate),
        })),
    };

    if (props.isEditing && props.estimate?.id) {
        router.put(route('invoicing.estimates.update', props.estimate.id), payload);
        return;
    }
    router.post(route('invoicing.estimates.store'), payload);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit Estimate' : 'Create Estimate'"
        :breadcrumbs="[
            { label: 'Money In' },
            { label: 'Estimates', href: route('invoicing.estimates.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <Head :title="isEditing ? 'Edit Estimate' : 'Create Estimate'" />
        <PageHeader :title="isEditing ? `Edit ${form.number}` : 'Create Estimate'" />

        <div class="space-y-6">
                <AppCard>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Client</label>
                            <AppSelect
                                :model-value="String(form.client_id)"
                                :options="clients.map((c) => ({ label: c.name, value: String(c.id) }))"
                                placeholder="Select client"
                                @update:model-value="form.client_id = Number($event)"
                            />
                        </div>
                        <div><label class="mb-1 block text-xs font-medium text-slate-500">Estimate number</label><AppInput v-model="form.number" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-slate-500">Issue date</label><AppInput v-model="form.issue_date" type="date" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-slate-500">Expiry date</label><AppInput v-model="form.expiry_date" type="date" /></div>
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
                        VAT is not applied on this estimate. Enable VAT registered and choose a default VAT rate in Company settings to charge VAT.
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
                                <tr v-for="(line, index) in lineItems" :key="line.row_key">
                                    <td class="w-10 px-1 py-3 align-top">
                                        <span
                                            class="estimate-line-drag-handle mt-1 inline-flex cursor-grab touch-manipulation rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 active:cursor-grabbing"
                                            role="button"
                                            tabindex="0"
                                            aria-label="Drag to reorder line"
                                        >
                                            <GripVertical class="h-4 w-4 shrink-0" />
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        <textarea
                                            :value="line.description"
                                            rows="3"
                                            placeholder="Line item description"
                                            class="min-h-[4.5rem] w-full resize-y rounded-md border border-slate-300 bg-white px-3 py-2 text-sm leading-snug text-slate-900 outline-none ring-slate-300 transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20"
                                            @input="updateLine(index, 'description', ($event.target as HTMLTextAreaElement).value)"
                                        />
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
                                            :options="vatSelectOptions"
                                            @update:model-value="updateLine(index, 'vat_rate', Number($event))"
                                        />
                                    </td>
                                    <td v-if="chargesVat" class="px-2 py-3 align-top text-right tabular-nums text-slate-700">
                                        {{ money(lineVatCents(line)) }}
                                    </td>
                                    <td class="px-2 py-3 align-top text-right text-base font-semibold tabular-nums text-slate-900">
                                        {{ money(lineTotalCents(line)) }}
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
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span>{{ money(totals.subtotal) }}</span></div>
                        <div v-if="chargesVat" class="flex justify-between"><span class="text-slate-500">VAT</span><span>{{ money(totals.vat) }}</span></div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold"><span>Total</span><span>{{ money(totals.total) }}</span></div>
                    </div>
                </AppCard>

                <AppCard>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                    <textarea v-model="form.notes" class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                    <label class="mb-1 mt-3 block text-xs font-medium text-slate-500">Terms</label>
                    <textarea v-model="form.terms" class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                </AppCard>
        </div>

        <div class="sticky bottom-0 mt-6 border-t border-slate-200 bg-white/95 px-2 py-3 backdrop-blur">
            <div class="flex justify-end gap-2">
                <AppButton variant="ghost" @click="router.visit(route('invoicing.estimates.index'))">Cancel</AppButton>
                <AppButton variant="primary" @click="submit('draft')">Save</AppButton>
            </div>
        </div>
    </AppLayout>
</template>
