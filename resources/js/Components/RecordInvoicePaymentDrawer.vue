<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { CalendarClock, X } from 'lucide-vue-next';

export type RecordPaymentInvoiceInput = {
    id: number;
    number: string;
    client_name?: string;
    client?: string;
    amount_due_cents: number;
    total_cents: number;
    currency: string;
    company_currency_code?: string | null;
    fx_rate_invoice_to_company?: string | null;
    fx_rate_date?: string | null;
    total_company_currency_cents?: number | null;
};

const props = defineProps<{
    open: boolean;
    invoice: RecordPaymentInvoiceInput | null;
    chargesVat: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const page = usePage<{
    errors?: Record<string, string | string[] | undefined>;
    company_currency?: string;
    invoice_payment_methods?: Array<{ value: string; label: string }>;
}>();

const paymentMethods = computed(
    () => page.props.invoice_payment_methods ?? [],
);

const companyCurrencyFallback = computed(() => page.props.company_currency ?? 'ZAR');

const normalizeCode = (code: string) => String(code || 'ZAR').trim().toUpperCase();

const clientLabel = computed(() => {
    const inv = props.invoice;
    if (!inv) return '';
    return inv.client_name ?? inv.client ?? 'Unknown';
});

const bookCurrency = computed(() => {
    const inv = props.invoice;
    if (!inv) return companyCurrencyFallback.value;
    return inv.company_currency_code
        ? normalizeCode(inv.company_currency_code)
        : normalizeCode(companyCurrencyFallback.value);
});

const isForeignBooked = computed(() => {
    const inv = props.invoice;
    if (!inv) return false;
    if (inv.total_company_currency_cents == null) return false;
    return normalizeCode(inv.currency) !== normalizeCode(bookCurrency.value);
});

const form = ref({
    amount: '',
    payment_date: '',
    method: 'eft',
    reference: '',
    notes: '',
    bank_amount_company: '',
    book_fx_loss_to_expense: false,
});

const spotHint = ref<{ rate: number; date: string } | null>(null);
const spotError = ref<string | null>(null);

const parseMajorToCents = (raw: string): number => {
    const n = Number(String(raw).replace(',', '.'));
    if (!Number.isFinite(n)) return 0;
    return Math.round(n * 100);
};

const paymentInvoiceCents = computed(() => parseMajorToCents(form.value.amount));

const bookClearingCompanyCents = computed(() => {
    const inv = props.invoice;
    if (!inv || !isForeignBooked.value) return null;
    const totalInv = Math.max(1, Number(inv.total_cents) || 0);
    const totalCo = Number(inv.total_company_currency_cents);
    if (!Number.isFinite(totalCo)) return null;
    const pay = paymentInvoiceCents.value;
    if (pay <= 0) return null;
    return Math.round((pay * totalCo) / totalInv);
});

const bankCompanyCents = computed(() => {
    if (!isForeignBooked.value) return null;
    const raw = form.value.bank_amount_company.trim();
    if (raw === '') return null;
    return parseMajorToCents(raw);
});

const fxDifferenceCents = computed(() => {
    if (!isForeignBooked.value || bookClearingCompanyCents.value == null) return null;
    const bank = bankCompanyCents.value ?? bookClearingCompanyCents.value;
    return bank - bookClearingCompanyCents.value;
});

const formatInv = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, props.invoice?.currency || 'ZAR');

const formatCo = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, bookCurrency.value);

const errorKeys = [
    'amount_cents',
    'payment_date',
    'method',
    'reference',
    'notes',
    'account',
    'invoice_id',
    'bank_amount_company_cents',
    'book_fx_loss_to_expense',
] as const;

const errorsList = computed(() => {
    const raw = page.props.errors;
    if (!raw || typeof raw !== 'object') return [] as { key: string; message: string }[];
    return errorKeys.flatMap((key) => {
        const val = raw[key];
        if (val === undefined || val === null) return [];
        const message = Array.isArray(val) ? val.join(' ') : String(val);
        return [{ key, message }];
    });
});

const resetForm = () => {
    const inv = props.invoice;
    form.value = {
        amount: inv ? ((Number(inv.amount_due_cents) || 0) / 100).toFixed(2) : '',
        payment_date: new Date().toISOString().slice(0, 10),
        method: 'eft',
        reference: '',
        notes: '',
        bank_amount_company: '',
        book_fx_loss_to_expense: false,
    };
    spotHint.value = null;
    spotError.value = null;
};

watch(
    () => [props.open, props.invoice?.id] as const,
    () => {
        if (props.open && props.invoice) {
            resetForm();
        }
    },
    { immediate: true },
);

watch(
    () => [
        props.open,
        props.invoice?.id ?? 0,
        props.invoice?.currency ?? '',
        props.invoice?.company_currency_code ?? '',
        props.invoice?.total_company_currency_cents ?? null,
        form.value.payment_date,
        companyCurrencyFallback.value,
    ],
    async () => {
        if (!props.open || !props.invoice) {
            spotHint.value = null;
            spotError.value = null;
            return;
        }
        const inv = props.invoice;
        const book = inv.company_currency_code
            ? normalizeCode(inv.company_currency_code)
            : normalizeCode(companyCurrencyFallback.value);
        const foreign =
            inv.total_company_currency_cents != null && normalizeCode(inv.currency) !== book;
        if (!foreign) {
            spotHint.value = null;
            spotError.value = null;
            return;
        }
        const from = normalizeCode(inv.currency);
        const to = book;
        if (from === to) return;
        try {
            const params = new URLSearchParams({ from, to });
            if (form.value.payment_date) params.set('date', form.value.payment_date);
            const res = await fetch(`${route('invoicing.exchange-rate')}?${params}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => null);
            if (!res.ok) {
                spotError.value =
                    (data && typeof data.message === 'string' && data.message) ||
                    'Could not load indicative rate.';
                spotHint.value = null;
                return;
            }
            spotHint.value = { rate: Number(data.rate), date: String(data.date) };
            spotError.value = null;
        } catch {
            spotError.value = 'Could not load indicative rate.';
            spotHint.value = null;
        }
    },
);

const indicativeBankCents = computed(() => {
    if (
        !isForeignBooked.value
        || spotHint.value == null
        || !Number.isFinite(spotHint.value.rate)
        || paymentInvoiceCents.value <= 0
    ) {
        return null;
    }
    return Math.round(paymentInvoiceCents.value * spotHint.value.rate);
});

const close = () => emit('update:open', false);

const submit = () => {
    const inv = props.invoice;
    if (!inv) return;
    const amountCents = paymentInvoiceCents.value;
    if (amountCents < 1) return;

    const body: Record<string, unknown> = {
        amount_cents: amountCents,
        payment_date: form.value.payment_date,
        method: form.value.method,
        reference: form.value.reference || null,
        notes: form.value.notes || null,
    };

    if (isForeignBooked.value) {
        const bank = bankCompanyCents.value;
        if (bank != null) {
            body.bank_amount_company_cents = bank;
        }
        if (form.value.book_fx_loss_to_expense) {
            body.book_fx_loss_to_expense = true;
        }
    }

    router.post(route('invoicing.invoices.payments.store', inv.id), body, {
        preserveScroll: true,
        onSuccess: () => {
            close();
        },
    });
};
</script>

<template>
    <div>
        <div
            v-if="open"
            class="fixed inset-0 z-[80] bg-black/40"
            @click="close"
        />
        <aside
            :class="[
                'fixed inset-y-0 right-0 z-[90] flex w-full max-w-md flex-col transform bg-white shadow-xl transition-transform',
                open ? 'translate-x-0' : 'translate-x-full pointer-events-none',
            ]"
            aria-labelledby="record-payment-title"
        >
            <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-5 py-4">
                <h3 id="record-payment-title" class="text-lg font-semibold text-slate-900">
                    Record payment
                </h3>
                <button
                    type="button"
                    class="rounded p-1 text-slate-600 hover:bg-slate-100"
                    aria-label="Close"
                    @click="close"
                >
                    <X class="h-5 w-5" />
                </button>
            </div>

            <div v-if="invoice" class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4 text-sm">
                <p class="text-slate-600">
                    Invoice <strong class="text-slate-900">{{ invoice.number }}</strong>
                    · {{ clientLabel }}
                </p>

                <div
                    v-if="errorsList.length"
                    class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900"
                >
                    <p class="font-medium">Could not record payment</p>
                    <ul class="mt-1 list-inside list-disc space-y-0.5 text-rose-800">
                        <li v-for="err in errorsList" :key="err.key">{{ err.message }}</li>
                    </ul>
                </div>

                <div
                    v-if="isForeignBooked"
                    class="rounded-md border border-sky-200 bg-sky-50/80 px-3 py-2 text-xs text-sky-950"
                >
                    <p class="font-medium text-sky-950">Foreign currency invoice</p>
                    <p class="mt-1 text-sky-900">
                        Payment amount is in <strong>{{ invoice.currency }}</strong>. The bank deposit is tracked in
                        <strong>{{ bookCurrency }}</strong> for your books.
                    </p>
                    <p v-if="spotHint" class="mt-1 text-sky-800">
                        Indicative rate ({{ spotHint.date }}): 1 {{ invoice.currency }} ≈
                        {{ spotHint.rate.toLocaleString(undefined, { maximumFractionDigits: 6 }) }}
                        {{ bookCurrency }}
                    </p>
                    <p v-else-if="spotError" class="mt-1 text-sky-800">{{ spotError }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        Amount ({{ invoice.currency }})
                    </label>
                    <AppInput
                        v-model="form.amount"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                    />
                    <p v-if="chargesVat" class="mt-1 text-xs text-slate-500">
                        VAT is allocated from this payment in proportion to the invoice total.
                    </p>
                </div>

                <template v-if="isForeignBooked && bookClearingCompanyCents != null">
                    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                        <p>
                            Book value of this payment ({{ bookCurrency }}):
                            <strong>{{ formatCo(bookClearingCompanyCents) }}</strong>
                        </p>
                        <p v-if="indicativeBankCents != null" class="mt-1 text-slate-600">
                            Indicative bank deposit at above rate:
                            <strong>{{ formatCo(indicativeBankCents) }}</strong>
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">
                            Bank received ({{ bookCurrency }}) — optional
                        </label>
                        <AppInput
                            v-model="form.bank_amount_company"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="Leave blank to match book value"
                        />
                        <p class="mt-1 text-xs text-slate-500">
                            If the amount that cleared your bank differs from the book value, enter it here to record
                            foreign exchange gain or loss.
                        </p>
                    </div>
                    <div
                        v-if="fxDifferenceCents != null && fxDifferenceCents !== 0"
                        :class="[
                            'rounded-md border px-3 py-2 text-xs',
                            fxDifferenceCents > 0
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-950'
                                : 'border-amber-200 bg-amber-50 text-amber-950',
                        ]"
                    >
                        <template v-if="fxDifferenceCents > 0">
                            <p class="font-medium">Foreign exchange gain</p>
                            <p class="mt-0.5">
                                {{ formatCo(fxDifferenceCents) }} will be posted to Foreign Exchange Gain (4950).
                            </p>
                        </template>
                        <template v-else>
                            <p class="font-medium">Foreign exchange loss</p>
                            <p class="mt-0.5">
                                {{ formatCo(Math.abs(fxDifferenceCents)) }} below book value — confirm expense posting
                                below.
                            </p>
                        </template>
                    </div>
                    <label
                        v-if="fxDifferenceCents != null && fxDifferenceCents < 0"
                        class="flex cursor-pointer items-start gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-xs text-slate-800"
                    >
                        <input
                            v-model="form.book_fx_loss_to_expense"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-slate-300"
                        >
                        <span>
                            Record foreign exchange loss to expenses (account 5900). Required when the bank amount is
                            below book value.
                        </span>
                    </label>
                </template>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Payment date</label>
                    <AppInput v-model="form.payment_date" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Method</label>
                    <AppSelect
                        v-model="form.method"
                        :options="paymentMethods.map((m) => ({ label: m.label, value: m.value }))"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Reference</label>
                    <AppInput v-model="form.reference" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                    <textarea v-model="form.notes" class="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2" />
                </div>
                <div class="flex justify-end pb-2">
                    <AppButton variant="primary" @click="submit">
                        <CalendarClock class="mr-1 h-4 w-4" />
                        Record payment
                    </AppButton>
                </div>
            </div>
        </aside>
    </div>
</template>
