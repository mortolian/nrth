<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { useToast } from '@/Composables/useToast';
import { CalendarClock, X } from 'lucide-vue-next';
export type RecordPaymentInvoiceInput = {
    id: number;
    number: string;
    client_name?: string;
    client?: string;
    amount_due_cents: number;
    total_cents: number;
    currency: string;
    business_currency_code?: string | null;
    fx_rate_invoice_to_business?: string | null;
    fx_rate_date?: string | null;
    total_business_currency_cents?: number | null;
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
    errors?: Record<string, string | string[]> | undefined;
    business_currency?: string;
    invoice_payment_methods?: Array<{ value: string; label: string }>;
    banking_deposit_accounts?: Array<{ id: number; name: string; gl_account_id: number; gl_label: string }>;
    fx_expense_accounts?: Array<{ id: number; label: string }>;
    fx_income_accounts?: Array<{ id: number; label: string }>;
    default_fx_loss_account_id?: number | null;
    default_fx_gain_account_id?: number | null;
}>();

const paymentMethods = computed(
    () => page.props.invoice_payment_methods ?? [],
);

const depositAccounts = computed(() => page.props.banking_deposit_accounts ?? []);

const fxExpenseAccountOptions = computed(() =>
    (page.props.fx_expense_accounts ?? []).map((account) => ({
        label: account.label,
        value: String(account.id),
    })),
);

const fxIncomeAccountOptions = computed(() =>
    (page.props.fx_income_accounts ?? []).map((account) => ({
        label: account.label,
        value: String(account.id),
    })),
);

const defaultFxLossAccountId = (): number => {
    const id = Number(page.props.default_fx_loss_account_id ?? 0);

    return id > 0 ? id : 0;
};

const defaultFxGainAccountId = (): number => {
    const id = Number(page.props.default_fx_gain_account_id ?? 0);

    return id > 0 ? id : 0;
};

const defaultDepositAccountId = (): number => {
    const bank = depositAccounts.value.find((option) => option.gl_label.startsWith('1010'));
    if (bank) {
        return bank.id;
    }

    return depositAccounts.value[0]?.id ?? 0;
};

const businessCurrencyFallback = computed(() => page.props.business_currency ?? 'ZAR');

const normalizeCode = (code: string) => String(code || 'ZAR').trim().toUpperCase();

const clientLabel = computed(() => {
    const inv = props.invoice;
    if (!inv) return '';
    return inv.client_name ?? inv.client ?? 'Unknown';
});

const bookCurrency = computed(() => {
    const inv = props.invoice;
    if (!inv) return businessCurrencyFallback.value;
    return inv.business_currency_code
        ? normalizeCode(inv.business_currency_code)
        : normalizeCode(businessCurrencyFallback.value);
});

const isForeignBooked = computed(() => {
    const inv = props.invoice;
    if (!inv) return false;
    if (inv.total_business_currency_cents == null) return false;
    return normalizeCode(inv.currency) !== normalizeCode(bookCurrency.value);
});

const form = ref({
    amount: '',
    payment_date: '',
    method: 'eft',
    banking_account_id: 0,
    reference: '',
    notes: '',
    bank_amount_business: '',
    book_fx_loss_to_expense: false,
    fx_loss_account_id: 0,
    fx_gain_account_id: 0,
});

const spotHint = ref<{ rate: number; date: string } | null>(null);
const spotError = ref<string | null>(null);
/** When true, user edited bank received — do not overwrite from spot rate. */
const bankAmountDirty = ref(false);

const centsToMajorInput = (cents: number): string => (cents / 100).toFixed(2);

const parseMajorToCents = (raw: string): number => {
    const n = Number(String(raw).replace(',', '.'));
    if (!Number.isFinite(n)) return 0;
    return Math.round(n * 100);
};

const paymentInvoiceCents = computed(() => parseMajorToCents(form.value.amount));

const bookClearingBusinessCents = computed(() => {
    const inv = props.invoice;
    if (!inv || !isForeignBooked.value) return null;
    const totalInv = Math.max(1, Number(inv.total_cents) || 0);
    const totalCo = Number(inv.total_business_currency_cents);
    if (!Number.isFinite(totalCo)) return null;
    const pay = paymentInvoiceCents.value;
    if (pay <= 0) return null;
    return Math.round((pay * totalCo) / totalInv);
});

const bankBusinessCents = computed(() => {
    if (!isForeignBooked.value) return null;
    const raw = form.value.bank_amount_business.trim();
    if (raw === '') return null;
    return parseMajorToCents(raw);
});

const fxDifferenceCents = computed(() => {
    if (!isForeignBooked.value || bookClearingBusinessCents.value == null) return null;
    const bank = bankBusinessCents.value ?? bookClearingBusinessCents.value;
    return bank - bookClearingBusinessCents.value;
});

const formatInv = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, props.invoice?.currency || 'ZAR');

const formatCo = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, bookCurrency.value);

const errorKeys = [
    'amount_cents',
    'payment_date',
    'method',
    'banking_account_id',
    'reference',
    'notes',
    'account',
    'invoice_id',
    'bank_amount_business_cents',
    'book_fx_loss_to_expense',
    'fx_loss_account_id',
    'fx_gain_account_id',
] as const;

const localErrors = ref<{ key: string; message: string }[]>([]);

const errorsList = computed(() => {
    if (localErrors.value.length) {
        return localErrors.value;
    }
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
        banking_account_id: defaultDepositAccountId(),
        reference: '',
        notes: '',
        bank_amount_business: '',
        book_fx_loss_to_expense: false,
        fx_loss_account_id: defaultFxLossAccountId(),
        fx_gain_account_id: defaultFxGainAccountId(),
    };
    spotHint.value = null;
    spotError.value = null;
    bankAmountDirty.value = false;
    localErrors.value = [];
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
        props.invoice?.business_currency_code ?? '',
        props.invoice?.total_business_currency_cents ?? null,
        form.value.payment_date,
        businessCurrencyFallback.value,
    ],
    async () => {
        if (!props.open || !props.invoice) {
            spotHint.value = null;
            spotError.value = null;
            return;
        }
        const inv = props.invoice;
        const book = inv.business_currency_code
            ? normalizeCode(inv.business_currency_code)
            : normalizeCode(businessCurrencyFallback.value);
        const foreign =
            inv.total_business_currency_cents != null && normalizeCode(inv.currency) !== book;
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
            // Seed only while empty so changing payment date does not overwrite bank received.
            seedBankAmountIfEmpty();
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

const seedBankAmountIfEmpty = () => {
    if (bankAmountDirty.value) {
        return;
    }
    if (form.value.bank_amount_business.trim() !== '') {
        return;
    }
    const cents = indicativeBankCents.value;
    if (cents == null) {
        return;
    }
    form.value.bank_amount_business = centsToMajorInput(cents);
};

/** When payment amount changes and the user has not edited bank received, refresh from current rate. */
watch(
    () => paymentInvoiceCents.value,
    (cents, previous) => {
        if (bankAmountDirty.value) {
            return;
        }
        if (previous === undefined) {
            return;
        }
        if (cents === previous) {
            return;
        }
        const indicative = indicativeBankCents.value;
        if (indicative == null) {
            return;
        }
        form.value.bank_amount_business = centsToMajorInput(indicative);
    },
);

const close = () => emit('update:open', false);

const toast = useToast();
const saving = ref(false);

const submit = () => {
    if (saving.value) return;

    const inv = props.invoice;
    if (!inv) return;
    const amountCents = paymentInvoiceCents.value;
    if (amountCents < 1) {
        localErrors.value = [{ key: 'amount', message: 'Enter a payment amount greater than zero.' }];
        return;
    }
    if (!form.value.payment_date) {
        localErrors.value = [{ key: 'payment_date', message: 'Payment date is required.' }];
        return;
    }
    if (!Number(form.value.banking_account_id || 0)) {
        localErrors.value = [{ key: 'banking_account_id', message: 'Select a deposit account.' }];
        return;
    }

    localErrors.value = [];

    const body: Record<string, unknown> = {
        amount_cents: amountCents,
        payment_date: form.value.payment_date,
        method: form.value.method,
        banking_account_id: Number(form.value.banking_account_id || 0),
        reference: form.value.reference || null,
        notes: form.value.notes || null,
    };

    if (isForeignBooked.value) {
        const bank = bankBusinessCents.value;
        if (bank != null) {
            body.bank_amount_business_cents = bank;
        }
        if (form.value.book_fx_loss_to_expense) {
            body.book_fx_loss_to_expense = true;
            if (Number(form.value.fx_loss_account_id || 0) > 0) {
                body.fx_loss_account_id = Number(form.value.fx_loss_account_id);
            }
        }
        if (
            fxDifferenceCents.value != null
            && fxDifferenceCents.value > 0
            && Number(form.value.fx_gain_account_id || 0) > 0
        ) {
            body.fx_gain_account_id = Number(form.value.fx_gain_account_id);
        }
    }

    router.post(route('invoicing.invoices.payments.store', inv.id), body, {
        preserveScroll: true,
        onStart: () => {
            saving.value = true;
        },
        onSuccess: () => {
            toast.success('Payment recorded.');
            close();
        },
        onError: (errors) => {
            if (!Object.keys(errors).length) {
                toast.error('Could not record this payment.');
            }
        },
        onFinish: () => {
            saving.value = false;
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
                        The amount on the invoice is in <strong>{{ invoice.currency }}</strong>.
                        What actually landed in your bank is in <strong>{{ bookCurrency }}</strong>.
                        Your books use {{ bookCurrency }} — any difference is foreign exchange gain or loss.
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
                        Amount on invoice ({{ invoice.currency }})
                    </label>
                    <AppInput
                        :model-value="form.amount"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        @update:model-value="(v) => { form.amount = v; localErrors = []; }"
                    />
                    <p v-if="localErrors.some((e) => e.key === 'amount')" class="mt-1 text-xs text-rose-600">
                        Enter a payment amount greater than zero.
                    </p>
                    <p v-if="chargesVat" class="mt-1 text-xs text-slate-500">
                        VAT is allocated from this payment in proportion to the invoice total.
                    </p>
                </div>

                <template v-if="isForeignBooked && bookClearingBusinessCents != null">
                    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                        <p>
                            Book value of this payment ({{ bookCurrency }}):
                            <strong>{{ formatCo(bookClearingBusinessCents) }}</strong>
                        </p>
                        <p class="mt-1 text-slate-600">
                            This is the invoice amount converted at the rate stored when the invoice was created.
                            Accounts receivable is cleared by this amount.
                        </p>
                        <p v-if="indicativeBankCents != null" class="mt-1 text-slate-600">
                            Suggested bank amount at today’s indicative rate:
                            <strong>{{ formatCo(indicativeBankCents) }}</strong>
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">
                            Amount in bank ({{ bookCurrency }})
                        </label>
                        <AppInput
                            :model-value="form.bank_amount_business"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="What hit your bank account"
                            @update:model-value="(v) => { bankAmountDirty = true; form.bank_amount_business = v; }"
                        />
                        <p class="mt-1 text-xs text-slate-500">
                            Enter what actually cleared your bank. If this differs from book value, the difference is
                            recorded as foreign exchange gain or loss.
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
                            <p class="font-medium">You received more than book value</p>
                            <p class="mt-0.5">
                                {{ formatCo(fxDifferenceCents) }} will be recorded as foreign exchange gain
                                (extra income).
                            </p>
                        </template>
                        <template v-else>
                            <p class="font-medium">You received less than book value</p>
                            <p class="mt-0.5">
                                {{ formatCo(Math.abs(fxDifferenceCents)) }} short — confirm below to record this as
                                a foreign exchange loss (expense).
                            </p>
                        </template>
                    </div>
                    <div
                        v-if="fxDifferenceCents != null && fxDifferenceCents > 0"
                        class="space-y-1"
                    >
                        <label class="mb-1 block text-xs font-medium text-slate-500">FX gain account</label>
                        <AppSelect
                            :model-value="String(form.fx_gain_account_id || '')"
                            :options="fxIncomeAccountOptions"
                            placeholder="Select income account"
                            @update:model-value="form.fx_gain_account_id = Number($event || 0)"
                        />
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
                            Yes — record the shortfall as a foreign exchange loss (expense). Required when the bank
                            amount is below book value.
                        </span>
                    </label>
                    <div
                        v-if="fxDifferenceCents != null && fxDifferenceCents < 0 && form.book_fx_loss_to_expense"
                        class="space-y-1"
                    >
                        <label class="mb-1 block text-xs font-medium text-slate-500">FX loss account</label>
                        <AppSelect
                            :model-value="String(form.fx_loss_account_id || '')"
                            :options="fxExpenseAccountOptions"
                            placeholder="Select expense account"
                            @update:model-value="form.fx_loss_account_id = Number($event || 0)"
                        />
                    </div>
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
                    <label class="mb-1 block text-xs font-medium text-slate-500">Paid into</label>
                    <AppSelect
                        :model-value="String(form.banking_account_id || '')"
                        :options="depositAccounts.map((option) => ({ label: `${option.name} (${option.gl_label})`, value: String(option.id) }))"
                        placeholder="Select banking account"
                        @update:model-value="form.banking_account_id = Number($event)"
                    />
                    <p class="mt-1 text-xs text-slate-500">Which bank or cash account received this payment.</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Reference</label>
                    <AppInput v-model="form.reference" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                    <textarea v-model="form.notes" class="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2" />
                </div>
                <FormActions class="!mt-4 pb-2">
                    <AppButton variant="primary" :loading="saving" @click="submit">
                        <CalendarClock v-if="!saving" class="mr-1 h-4 w-4" />
                        {{ saving ? 'Recording…' : 'Record payment' }}
                    </AppButton>
                </FormActions>
            </div>
        </aside>
    </div>
</template>
