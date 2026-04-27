<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppCard from '@/Components/AppCard.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type ClientOption = { id: number; name: string };
type TaxRateOption = { id: number; name: string; rate: number; is_default: boolean };
type QuoteLine = { description: string; quantity: number; unit_price_cents: number; vat_rate: number };
type QuotePayload = {
    id: number;
    client_id: number;
    number: string;
    issue_date: string;
    expiry_date: string;
    notes: string | null;
    terms: string | null;
    line_items: QuoteLine[];
};

const props = defineProps<{
    isEditing: boolean;
    quote: QuotePayload | null;
    clients: ClientOption[];
    tax_rates: TaxRateOption[];
    charges_vat: boolean;
    next_number: string;
}>();

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

const form = ref({
    client_id: props.quote?.client_id ?? props.clients[0]?.id ?? 0,
    number: props.quote?.number ?? props.next_number,
    issue_date: props.quote?.issue_date ?? new Date().toISOString().slice(0, 10),
    expiry_date: props.quote?.expiry_date ?? new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10),
    notes: props.quote?.notes ?? '',
    terms: props.quote?.terms ?? '50% deposit on acceptance. Balance due on delivery.',
});

const lineItems = ref<QuoteLine[]>(
    props.quote?.line_items?.length
        ? props.quote.line_items.map((row) => ({ ...row }))
        : [{ description: '', quantity: 1, unit_price_cents: 0, vat_rate: defaultLineVat.value }],
);

watch(
    chargesVat,
    (on) => {
        if (!on) {
            lineItems.value = lineItems.value.map((row) => ({ ...row, vat_rate: 0 }));
        }
    },
    { immediate: true },
);

const totals = computed(() => {
    const subtotal = lineItems.value.reduce((acc, row) => acc + Math.round(row.quantity * row.unit_price_cents), 0);
    const vat = lineItems.value.reduce((acc, row) => acc + Math.round(row.quantity * row.unit_price_cents * row.vat_rate), 0);
    return { subtotal, vat, total: subtotal + vat };
});

const money = (cents: number) => useFormatCurrency(cents / 100, 'ZAR');

const submit = (submitAction: 'draft' | 'send') => {
    const payload = {
        ...form.value,
        submit_action: submitAction,
        line_items: lineItems.value,
    };

    if (props.isEditing && props.quote?.id) {
        router.put(route('invoicing.quotes.update', props.quote.id), payload);
        return;
    }
    router.post(route('invoicing.quotes.store'), payload);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit Quote' : 'Create Quote'"
        :breadcrumbs="[
            { label: 'Money In' },
            { label: 'Quotes', href: route('invoicing.quotes.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <Head :title="isEditing ? 'Edit Quote' : 'Create Quote'" />
        <PageHeader :title="isEditing ? `Edit ${form.number}` : 'Create Quote'" subtitle="Capture quote details, scope, and pricing." />

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
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
                        <div><label class="mb-1 block text-xs font-medium text-slate-500">Quote number</label><AppInput v-model="form.number" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-slate-500">Issue date</label><AppInput v-model="form.issue_date" type="date" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-slate-500">Expiry date</label><AppInput v-model="form.expiry_date" type="date" /></div>
                    </div>
                </AppCard>

                <AppCard>
                    <p v-if="!chargesVat" class="mb-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        VAT is not applied on this quote. Enable VAT registered and choose a default VAT rate in Company settings to charge VAT.
                    </p>
                    <h3 class="mb-3 text-base font-semibold text-slate-900">Line items</h3>
                    <div class="space-y-3">
                        <div v-for="(row, idx) in lineItems" :key="idx" class="grid gap-2 md:grid-cols-12">
                            <AppInput v-model="row.description" class="md:col-span-5" placeholder="Description" />
                            <AppInput v-model.number="row.quantity" type="number" class="md:col-span-2" placeholder="Qty" />
                            <AppInput v-model.number="row.unit_price_cents" type="number" class="md:col-span-3" placeholder="Unit cents" />
                            <AppSelect
                                :model-value="String(row.vat_rate)"
                                :options="vatSelectOptions"
                                :disabled="!chargesVat"
                                class="md:col-span-2"
                                @update:model-value="row.vat_rate = Number($event)"
                            />
                        </div>
                    </div>
                    <div class="mt-3 flex justify-center border-t border-slate-200 pt-3">
                        <AppButton
                            size="sm"
                            variant="secondary"
                            @click="lineItems.push({ description: '', quantity: 1, unit_price_cents: 0, vat_rate: defaultLineVat })"
                        >
                            Add line
                        </AppButton>
                    </div>
                </AppCard>

                <AppCard>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                    <textarea v-model="form.notes" class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                    <label class="mb-1 mt-3 block text-xs font-medium text-slate-500">Terms</label>
                    <textarea v-model="form.terms" class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                </AppCard>
            </div>

            <div class="space-y-6">
                <AppCard>
                    <h3 class="text-base font-semibold text-slate-900">Totals</h3>
                    <div class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span>{{ money(totals.subtotal) }}</span></div>
                        <div class="flex justify-between"><span class="text-slate-500">VAT</span><span>{{ money(totals.vat) }}</span></div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold"><span>Total</span><span>{{ money(totals.total) }}</span></div>
                    </div>
                </AppCard>
            </div>
        </div>

        <div class="sticky bottom-0 mt-6 border-t border-slate-200 bg-white/95 px-2 py-3 backdrop-blur">
            <div class="flex justify-end gap-2">
                <AppButton variant="ghost" @click="router.visit(route('invoicing.quotes.index'))">Cancel</AppButton>
                <AppButton variant="secondary" @click="submit('draft')">Save Draft</AppButton>
                <AppButton variant="primary" @click="submit('send')">Save and Send</AppButton>
            </div>
        </div>
    </AppLayout>
</template>

