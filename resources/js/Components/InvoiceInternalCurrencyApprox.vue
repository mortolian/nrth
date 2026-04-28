<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

const props = defineProps<{
    invoiceCurrency: string;
    /** Company default from settings (invoice_default_currency). */
    companyCurrency: string;
    totalCents: number;
    amountDueCents?: number;
}>();

const loading = ref(false);
const error = ref<string | null>(null);
const rate = ref<number | null>(null);
const rateDate = ref<string | null>(null);

const visible = computed(
    () =>
        props.invoiceCurrency
        && props.companyCurrency
        && props.invoiceCurrency !== props.companyCurrency,
);

async function loadRate(): Promise<void> {
    if (!visible.value) {
        error.value = null;
        rate.value = null;
        rateDate.value = null;
        return;
    }

    loading.value = true;
    error.value = null;
    rate.value = null;
    rateDate.value = null;

    try {
        const url = `${route('invoicing.exchange-rate')}?${new URLSearchParams({
            from: props.invoiceCurrency,
            to: props.companyCurrency,
        })}`;
        const res = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = (await res.json()) as { rate?: number; date?: string; message?: string };
        if (!res.ok) {
            error.value = data.message || 'Could not load exchange rate.';
            return;
        }
        if (typeof data.rate !== 'number' || !Number.isFinite(data.rate)) {
            error.value = 'Invalid rate response.';
            return;
        }
        rate.value = data.rate;
        rateDate.value = data.date ?? null;
    } catch {
        error.value = 'Could not load exchange rate.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.invoiceCurrency, props.companyCurrency],
    () => {
        void loadRate();
    },
    { immediate: true },
);

function convertMajor(cents: number): string {
    if (rate.value === null) return '—';
    const major = (Number(cents) || 0) / 100;
    const converted = major * rate.value;
    return useFormatCurrency(converted, props.companyCurrency);
}
</script>

<template>
    <div
        v-if="visible"
        class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700"
    >
        <p class="font-medium text-slate-800">Approximate in {{ companyCurrency }} (internal)</p>
        <p v-if="loading" class="mt-1 text-slate-500">Loading reference rate (Frankfurter)…</p>
        <p v-else-if="error" class="mt-1 text-rose-700">{{ error }}</p>
        <template v-else-if="rate !== null">
            <p class="mt-1">
                <span class="text-slate-500">Total:</span>
                {{ convertMajor(totalCents) }}
            </p>
            <p v-if="amountDueCents !== undefined" class="mt-0.5">
                <span class="text-slate-500">Amount due:</span>
                {{ convertMajor(amountDueCents) }}
            </p>
            <p v-if="rateDate" class="mt-1.5 text-[11px] text-slate-500">
                1 {{ invoiceCurrency }} ≈ {{ rate.toFixed(4) }} {{ companyCurrency }} · Rate date {{ rateDate }} (Frankfurter) · Indicative only; not shown on PDF or client-facing invoice.
            </p>
        </template>
    </div>
</template>
