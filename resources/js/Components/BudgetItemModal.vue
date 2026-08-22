<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import DialogModal from '@/Components/DialogModal.vue';
import { useToast } from '@/Composables/useToast';

type ItemCadence = 'monthly' | 'annually' | 'once_per_period';

type ItemInput = {
    id: number;
    label: string;
    cadence: ItemCadence;
    notes: string | null;
    monthly_amount_cents: number;
    currency: string;
    fx_budget_per_line_major: string | null;
} | null;

const props = defineProps<{
    show: boolean;
    budgetId: number;
    categoryId: number | null;
    budgetCurrency: string;
    item: ItemInput;
}>();

const emit = defineEmits<{
    close: [];
}>();

const page = usePage();
const toast = useToast();
const processing = ref(false);
const fxLoading = ref(false);
const fxError = ref('');
const errors = reactive<Record<string, string>>({});

const form = reactive({
    label: '',
    cadence: 'monthly' as ItemCadence,
    notes: '',
    monthly_major: '',
    currency: 'ZAR',
    fx_budget_per_line_major: '',
});

const currencyOptions = computed(
    () => (page.props.currencyOptions as Array<{ value: string; label: string }>) ?? [],
);

const cadenceOptions = [
    { label: 'Monthly', value: 'monthly' },
    { label: 'Annually', value: 'annually' },
    { label: 'Once per period', value: 'once_per_period' },
];

const isEditing = computed(() => props.item != null);
const currenciesDiffer = computed(
    () => form.currency.toUpperCase() !== props.budgetCurrency.toUpperCase(),
);
const amountLabel = computed(() => {
    if (form.cadence === 'once_per_period') {
        return 'Amount (once in this period)';
    }
    if (form.cadence === 'annually') {
        return 'Annual amount';
    }
    return 'Monthly amount';
});

function minorDigits(code: string): number {
    const c = code.toUpperCase();
    if (c === 'JPY' || c === 'KRW' || c === 'VND' || c === 'CLP' || c === 'UGX') return 0;
    if (c === 'BHD' || c === 'IQD' || c === 'JOD' || c === 'KWD' || c === 'LYD' || c === 'OMR' || c === 'TND') return 3;
    return 2;
}

function centsToMajorStr(cents: number, ccy: string): string {
    const d = minorDigits(ccy);
    return ((Number(cents) || 0) / 10 ** d).toFixed(d);
}

function normalizeMajorAmountForParse(raw: string): string {
    let s = raw.trim().replace(/\s/g, '');
    if (s === '' || s === '-') return s;
    const hasComma = s.includes(',');
    const hasDot = s.includes('.');
    if (hasComma && hasDot) {
        const lastComma = s.lastIndexOf(',');
        const lastDot = s.lastIndexOf('.');
        if (lastDot > lastComma) {
            s = s.replace(/,/g, '');
        } else {
            s = s.replace(/\./g, '').replace(',', '.');
        }
    } else if (hasComma && !hasDot) {
        const parts = s.split(',');
        if (parts.length === 2 && parts[1].length <= 3) {
            s = `${parts[0].replace(/\./g, '')}.${parts[1]}`;
        } else {
            s = s.replace(/,/g, '');
        }
    }
    return s.replace(/[^0-9.-]/g, '');
}

function majorStrToCents(raw: string, ccy: string): number | null {
    const normalized = normalizeMajorAmountForParse(raw);
    if (normalized === '' || normalized === '-') return null;
    const n = Number(normalized);
    if (!Number.isFinite(n) || n < 0) return null;
    const d = minorDigits(ccy);
    return Math.round(n * 10 ** d);
}

function formatRateForStorage(rate: number): string {
    const s = rate.toFixed(10).replace(/\.?0+$/, '');
    return s === '' ? String(rate) : s;
}

async function fetchFxRate(): Promise<void> {
    if (!currenciesDiffer.value) {
        form.fx_budget_per_line_major = '';
        fxError.value = '';
        return;
    }

    fxLoading.value = true;
    fxError.value = '';
    try {
        const params = new URLSearchParams({
            from: form.currency,
            to: props.budgetCurrency,
        });
        const res = await fetch(`${route('invoicing.exchange-rate')}?${params}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = (await res.json().catch(() => null)) as {
            rate?: number;
            message?: string;
            errors?: Record<string, string[]>;
        } | null;
        if (!res.ok || data?.rate == null || !(data.rate > 0)) {
            const fieldError =
                data?.errors?.from?.[0] || data?.errors?.to?.[0] || data?.errors?.date?.[0];
            fxError.value = fieldError || data?.message || 'Could not fetch exchange rate.';
            return;
        }
        form.fx_budget_per_line_major = formatRateForStorage(data.rate);
    } catch {
        fxError.value = 'Could not fetch exchange rate.';
    } finally {
        fxLoading.value = false;
    }
}

watch(
    () => [props.show, props.item, props.budgetCurrency] as const,
    async ([show]) => {
        if (!show) return;
        Object.keys(errors).forEach((k) => delete errors[k]);
        fxError.value = '';
        if (props.item) {
            form.label = props.item.label;
            form.cadence =
                props.item.cadence === 'once_per_period' || props.item.cadence === 'annually'
                    ? props.item.cadence
                    : 'monthly';
            form.notes = props.item.notes ?? '';
            form.currency = props.item.currency || props.budgetCurrency;
            form.monthly_major = centsToMajorStr(props.item.monthly_amount_cents, form.currency);
            form.fx_budget_per_line_major = props.item.fx_budget_per_line_major ?? '';
            if (currenciesDiffer.value && !form.fx_budget_per_line_major) {
                await fetchFxRate();
            }
        } else {
            form.label = '';
            form.cadence = 'monthly';
            form.notes = '';
            form.currency = props.budgetCurrency;
            form.monthly_major = '';
            form.fx_budget_per_line_major = '';
        }
    },
);

watch(
    () => form.currency,
    async (ccy, prev) => {
        if (!props.show || ccy === prev) return;
        if (!currenciesDiffer.value) {
            form.fx_budget_per_line_major = '';
            fxError.value = '';
            return;
        }
        if (!form.fx_budget_per_line_major) {
            await fetchFxRate();
        }
    },
);

const close = () => {
    if (processing.value) return;
    emit('close');
};

const submit = () => {
    if (processing.value || props.categoryId == null) return;
    Object.keys(errors).forEach((k) => delete errors[k]);

    const label = form.label.trim();
    if (!label) {
        errors.label = 'Label is required.';
        return;
    }

    const amountCents = majorStrToCents(form.monthly_major === '' ? '0' : form.monthly_major, form.currency);
    if (amountCents === null) {
        errors.monthly_amount_cents =
            form.cadence === 'monthly' ? 'Enter a valid monthly amount.' : 'Enter a valid amount.';
        return;
    }

    if (currenciesDiffer.value) {
        const fx = form.fx_budget_per_line_major.trim();
        if (!fx || !(Number(fx) > 0)) {
            errors.fx_budget_per_line_major =
                'Enter the exchange rate from the line currency to the budget currency.';
            return;
        }
    }

    const payload = {
        label,
        cadence: form.cadence,
        notes: form.notes.trim() === '' ? null : form.notes.trim(),
        monthly_amount_cents: amountCents,
        currency: form.currency,
        fx_budget_per_line_major: currenciesDiffer.value ? form.fx_budget_per_line_major.trim() : null,
    };

    const visitOptions = {
        preserveScroll: true,
        onStart: () => {
            processing.value = true;
        },
        onSuccess: () => {
            toast.success(isEditing.value ? 'Line item updated.' : 'Line item added.');
            emit('close');
        },
        onError: (serverErrors: Record<string, string>) => {
            Object.assign(errors, serverErrors);
            if (!Object.keys(serverErrors).length) {
                toast.error('Could not save this line item.');
            }
        },
        onFinish: () => {
            processing.value = false;
        },
    };

    if (isEditing.value && props.item) {
        router.put(
            route('budgeting.items.update', [props.budgetId, props.categoryId, props.item.id]),
            payload,
            visitOptions,
        );
        return;
    }

    router.post(
        route('budgeting.items.store', [props.budgetId, props.categoryId]),
        payload,
        visitOptions,
    );
};
</script>

<template>
    <DialogModal :show="show" max-width="lg" @close="close">
        <template #title>
            {{ isEditing ? 'Edit line item' : 'Add line item' }}
        </template>
        <template #content>
            <div class="space-y-4 text-left text-slate-900">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        Label <span class="text-rose-600">*</span>
                    </label>
                    <AppInput v-model="form.label" placeholder="e.g. Software subscriptions" />
                    <p v-if="errors.label" class="mt-1 text-xs text-rose-600">{{ errors.label }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Cadence</label>
                    <AppSelect
                        :model-value="form.cadence"
                        :options="cadenceOptions"
                        @update:model-value="form.cadence = $event as ItemCadence"
                    />
                    <p class="mt-1 text-xs text-slate-500">
                        <template v-if="form.cadence === 'once_per_period'">
                            Counted once for the whole budget period.
                        </template>
                        <template v-else-if="form.cadence === 'annually'">
                            Enter the yearly amount; monthly and period totals are derived from it.
                        </template>
                        <template v-else>
                            Recurs every month; period total multiplies by months in the budget.
                        </template>
                    </p>
                    <p v-if="errors.cadence" class="mt-1 text-xs text-rose-600">{{ errors.cadence }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Currency</label>
                        <AppSelect
                            :model-value="form.currency"
                            :options="currencyOptions"
                            @update:model-value="form.currency = $event"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ amountLabel }}</label>
                        <AppInput v-model="form.monthly_major" placeholder="0.00" inputmode="decimal" />
                        <p v-if="errors.monthly_amount_cents" class="mt-1 text-xs text-rose-600">
                            {{ errors.monthly_amount_cents }}
                        </p>
                    </div>
                </div>
                <div v-if="currenciesDiffer">
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        FX rate ({{ budgetCurrency }} per 1 {{ form.currency }})
                    </label>
                    <div class="flex gap-2">
                        <AppInput
                            v-model="form.fx_budget_per_line_major"
                            class="flex-1"
                            placeholder="e.g. 18.5"
                            inputmode="decimal"
                        />
                        <AppButton
                            type="button"
                            variant="secondary"
                            :disabled="fxLoading || processing"
                            @click="fetchFxRate"
                        >
                            {{ fxLoading ? 'Loading…' : 'Fetch rate' }}
                        </AppButton>
                    </div>
                    <p v-if="fxError" class="mt-1 text-xs text-amber-700">{{ fxError }}</p>
                    <p v-if="errors.fx_budget_per_line_major" class="mt-1 text-xs text-rose-600">
                        {{ errors.fx_budget_per_line_major }}
                    </p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        class="min-h-[4.5rem] w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none ring-slate-300 transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25"
                        placeholder="Optional context (vendor, renewal month, etc.)"
                    />
                    <p v-if="errors.notes" class="mt-1 text-xs text-rose-600">{{ errors.notes }}</p>
                </div>
            </div>
        </template>
        <template #footer>
            <div class="flex justify-end gap-2">
                <AppButton variant="secondary" :disabled="processing" @click="close">Cancel</AppButton>
                <AppButton variant="primary" :disabled="processing || fxLoading" @click="submit">
                    {{ isEditing ? 'Save line' : 'Add line' }}
                </AppButton>
            </div>
        </template>
    </DialogModal>
</template>
