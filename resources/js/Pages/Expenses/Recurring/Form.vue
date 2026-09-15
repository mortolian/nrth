<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import FieldHelp from '@/Components/FieldHelp.vue';
import FormValidationBanner from '@/Components/FormValidationBanner.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useToast } from '@/Composables/useToast';
import { FALLBACK_EXPENSE_TAX_RATES, type ExpenseTaxRateOption } from '@/Pages/Expenses/fallbackTaxRates';

type SupplierOption = { id: number; name: string };
type CategoryOption = { id: number; name: string };
type PaidFromOption = { id: number; name: string; gl_label: string };
type VatRate = 'vat15' | 'vat0' | 'exempt' | 'no_vat';

const props = defineProps<{
    isEditing: boolean;
    recurring: null | Record<string, any>;
    categories: CategoryOption[];
    paid_from_options: PaidFromOption[];
    supplier_options: SupplierOption[];
    tax_rates?: ExpenseTaxRateOption[];
}>();

const toast = useToast();
const page = usePage();
const { fieldErrors, setFromServer, clear, messages: clientErrorMessages } = useFieldErrors();
const taxRateList = computed(() => (props.tax_rates?.length ? props.tax_rates : FALLBACK_EXPENSE_TAX_RATES));
const createdSuppliers = ref<SupplierOption[]>([]);
const saveSupplierLoading = ref(false);
const saveSupplierError = ref<string | null>(null);

const help = {
    supplier: 'Who this expense is paid to each cycle.',
    category: 'Expense account used when each occurrence is posted.',
    frequency: 'How often a new expense is created from this template.',
    nextRun: 'First (or next) date the daily job will pick this up. That day becomes the expense date.',
    weekday: 'ISO weekday (1=Mon … 7=Sun). The expense is posted that day.',
    generateDay: 'Day of month (1–28), unless you use last day of month.',
    generateMonth: 'Calendar month for yearly schedules.',
    periodOffset:
        'Shifts month placeholders vs the expense date. Example: −1 = Rent for June when posting on 1 July.',
    limits: 'When the schedule stops: never, after N expenses, or after an end date.',
    description: 'Use tokens like {{month_year}}, {{month}}, {{year}}, {{issue_date}}, {{day}}. They resolve when each expense is generated.',
    amount: 'Paid total including VAT. Excl. VAT and VAT are derived from the rate you select.',
    paidFrom: 'Bank, cash, or card account credited when each expense is posted.',
};

const initialExclCents = props.recurring?.amount_excl_vat_cents != null
    ? Number(props.recurring.amount_excl_vat_cents)
    : 0;
const initialVatCents = props.recurring?.vat_amount_cents != null
    ? Number(props.recurring.vat_amount_cents)
    : 0;

const form = ref({
    supplier_id: Number(props.recurring?.supplier_id ?? 0),
    supplier_custom: String(props.recurring?.supplier_custom ?? ''),
    category_account_id: Number(props.recurring?.category_account_id ?? 0),
    paid_from_banking_account_id: Number(
        props.recurring?.paid_from_banking_account_id ?? props.paid_from_options[0]?.id ?? 0,
    ),
    frequency: String(props.recurring?.frequency ?? 'monthly'),
    generate_on_weekday: Number(props.recurring?.generate_on_weekday ?? 1),
    generate_on_day: Number(props.recurring?.generate_on_day ?? 1),
    generate_on_last_day: Boolean(props.recurring?.generate_on_last_day ?? false),
    generate_on_month: Number(props.recurring?.generate_on_month ?? 1),
    limit_type: String(props.recurring?.limit_type ?? 'none'),
    limit_count: props.recurring?.limit_count ?? null,
    limit_end_date: props.recurring?.limit_end_date ?? null,
    next_run_date: String(props.recurring?.next_run_date ?? new Date().toISOString().slice(0, 10)),
    period_offset_months: Number(props.recurring?.period_offset_months ?? 0),
    description: String(props.recurring?.description ?? 'Rent for {{month_year}}'),
    notes: String(props.recurring?.notes ?? ''),
    reference: String(props.recurring?.reference ?? ''),
    amount_incl_vat: (initialExclCents + initialVatCents) / 100,
    vat_rate: (props.recurring?.vat_rate ?? 'no_vat') as VatRate,
});

const saving = ref(false);

const prefillSource = computed(() => String(props.recurring?.prefill_source ?? ''));
const prefillHint = computed(() => {
    if (props.isEditing) {
        return null;
    }
    if (prefillSource.value === 'expense') {
        return 'Prefilled from an existing expense — adjust the schedule and save.';
    }
    if (prefillSource.value === 'banking') {
        return 'Prefilled from a bank debit (amount treated as VAT-inclusive at 15%) — pick a category and adjust as needed.';
    }
    return null;
});

const selectedTax = computed(
    () => taxRateList.value.find((rate) => rate.value === form.value.vat_rate) ?? taxRateList.value[0],
);

const normalizeMoneyInput = (raw: unknown): number => {
    const cleaned = String(raw ?? '').trim().replace(',', '.');
    if (cleaned === '') return 0;
    const parsed = Number(cleaned);
    if (!Number.isFinite(parsed) || parsed < 0) return 0;
    return Number(parsed.toFixed(2));
};

const amountBreakdown = computed(() => {
    const inclCents = Math.round(normalizeMoneyInput(form.value.amount_incl_vat) * 100);
    const rate = Number(selectedTax.value?.rate || 0);
    if (rate > 0) {
        const exclCents = Math.round(inclCents / (1 + rate));
        return {
            exclCents,
            vatCents: inclCents - exclCents,
            inclCents,
        };
    }
    return { exclCents: inclCents, vatCents: 0, inclCents };
});

const amountExclVatDisplay = computed(() => (amountBreakdown.value.exclCents / 100).toFixed(2));
const vatAmountDisplay = computed(() => (amountBreakdown.value.vatCents / 100).toFixed(2));

const supplierList = computed(() => {
    const byId = new Map<number, SupplierOption>();
    for (const supplier of props.supplier_options) {
        byId.set(supplier.id, supplier);
    }
    for (const supplier of createdSuppliers.value) {
        byId.set(supplier.id, supplier);
    }
    return [...byId.values()].sort((a, b) => a.name.localeCompare(b.name));
});

const supplierSelectOptions = computed(() => [
    { label: 'Custom (one-off)', value: '0' },
    ...supplierList.value.map((supplier) => ({ label: supplier.name, value: String(supplier.id) })),
]);

const canSaveAsSupplier = computed(
    () => form.value.supplier_id === 0 && String(form.value.supplier_custom).trim().length > 0,
);

const recurringReturnPath = computed(() =>
    props.isEditing && props.recurring?.id
        ? `/expenses/recurring/${props.recurring.id}/edit`
        : '/expenses/recurring/create',
);

const openNewSupplierForm = () => {
    const query: Record<string, string> = { return: recurringReturnPath.value };
    const name = String(form.value.supplier_custom).trim();
    if (form.value.supplier_id === 0 && name) {
        query.name = name;
    }
    router.get(route('suppliers.create'), query);
};

const saveAsSupplier = async () => {
    const name = String(form.value.supplier_custom).trim();
    if (!name || saveSupplierLoading.value) {
        return;
    }

    const token = page.props.csrf_token as string | undefined;
    if (!token) {
        saveSupplierError.value = 'Unable to save supplier: missing security token. Refresh the page and try again.';
        return;
    }

    saveSupplierLoading.value = true;
    saveSupplierError.value = null;

    try {
        const res = await fetch(route('suppliers.store'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify({
                name,
                contact_name: null,
                email: null,
                phone: null,
                vat_number: null,
                registration_number: null,
                address: null,
                notes: null,
                is_active: true,
            }),
        });

        const responsePayload = (await res.json().catch(() => null)) as {
            data?: { id?: number; name?: string };
            message?: string;
            errors?: Record<string, string[]>;
        } | null;

        if (!res.ok) {
            const firstError = responsePayload?.errors
                ? Object.values(responsePayload.errors).flat()[0]
                : null;
            saveSupplierError.value = firstError || responsePayload?.message || 'Could not save this supplier.';
            toast.error(saveSupplierError.value);
            return;
        }

        const id = Number(responsePayload?.data?.id ?? 0);
        const savedName = String(responsePayload?.data?.name ?? name);
        if (id <= 0) {
            saveSupplierError.value = 'Could not save this supplier.';
            toast.error(saveSupplierError.value);
            return;
        }

        createdSuppliers.value = [...createdSuppliers.value, { id, name: savedName }];
        form.value.supplier_id = id;
        form.value.supplier_custom = '';
        toast.success('Supplier saved and selected.');
    } catch {
        saveSupplierError.value = 'Could not save this supplier. Try again.';
        toast.error(saveSupplierError.value);
    } finally {
        saveSupplierLoading.value = false;
    }
};

watch(
    () => [form.value.supplier_id, form.value.supplier_custom],
    () => {
        saveSupplierError.value = null;
    },
);

const categorySelectOptions = computed(() =>
    props.categories.map((category) => ({ label: category.name, value: String(category.id) })),
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

const placeholderTokens = [
    { token: '{{month_year}}', label: '{{month_year}}' },
    { token: '{{month}}', label: '{{month}}' },
    { token: '{{year}}', label: '{{year}}' },
    { token: '{{issue_date}}', label: '{{issue_date}}' },
] as const;

const insertToken = (token: string) => {
    form.value.description = `${form.value.description || ''}${token}`;
};

const submit = () => {
    if (saving.value) return;

    if (!form.value.category_account_id) {
        toast.error('Choose a category.');
        return;
    }
    if (!form.value.paid_from_banking_account_id) {
        toast.error('Choose a paid-from account.');
        return;
    }
    if (!form.value.supplier_id && !String(form.value.supplier_custom).trim()) {
        toast.error('Choose a supplier or enter a one-off name.');
        return;
    }

    clear();

    const payload = {
        supplier_id: form.value.supplier_id || null,
        supplier: form.value.supplier_id ? null : String(form.value.supplier_custom).trim(),
        category_account_id: Number(form.value.category_account_id),
        paid_from_banking_account_id: Number(form.value.paid_from_banking_account_id),
        frequency: form.value.frequency,
        generate_on_weekday: form.value.frequency === 'weekly'
            ? Number(form.value.generate_on_weekday)
            : null,
        generate_on_day: form.value.frequency === 'weekly' || form.value.generate_on_last_day
            ? null
            : Number(form.value.generate_on_day),
        generate_on_last_day: Boolean(form.value.generate_on_last_day),
        generate_on_month: form.value.frequency === 'yearly'
            ? Number(form.value.generate_on_month)
            : null,
        limit_type: form.value.limit_type,
        limit_count: form.value.limit_type === 'count' ? Number(form.value.limit_count) : null,
        limit_end_date: form.value.limit_type === 'end_date' ? form.value.limit_end_date : null,
        next_run_date: form.value.next_run_date,
        period_offset_months: Number(form.value.period_offset_months) || 0,
        description: form.value.description || null,
        notes: form.value.notes || null,
        reference: form.value.reference || null,
        amount_excl_vat_cents: amountBreakdown.value.exclCents,
        vat_rate: form.value.vat_rate,
        vat_amount_cents: amountBreakdown.value.vatCents,
    };

    const opts = {
        onStart: () => {
            saving.value = true;
        },
        onFinish: () => {
            saving.value = false;
        },
        onSuccess: () => {
            toast.success(props.isEditing ? 'Recurring expense saved.' : 'Recurring expense created.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
            if (!Object.keys(errors).length) {
                toast.error('Could not save this recurring expense.');
            }
        },
    };

    if (props.isEditing && props.recurring?.id) {
        router.put(route('expenses.recurring.update', props.recurring.id), payload, opts);
        return;
    }

    router.post(route('expenses.recurring.store'), payload, opts);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit recurring expense' : 'New recurring expense'"
        :breadcrumbs="[
            { label: 'Money Out', href: route('expenses.index') },
            { label: 'Recurring', href: route('expenses.recurring.index') },
            { label: isEditing ? 'Edit' : 'New' },
        ]"
    >
        <PageHeader
            :title="isEditing ? 'Edit recurring expense' : 'New recurring expense'"
            subtitle="Schedule a repeating bill — nrth posts it on each run date"
        />

        <p
            v-if="prefillHint"
            class="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700"
        >
            {{ prefillHint }}
        </p>

        <FormValidationBanner
            class="mt-4"
            title="Could not save recurring expense"
            :errors="visibleValidationErrors"
        />

        <AppCard class="mt-5 space-y-6">
            <section class="space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Supplier &amp; amount</h3>
                    <p class="mt-0.5 text-xs text-slate-500">What is paid each cycle</p>
                </div>
                <div>
                    <div class="mb-1 flex flex-wrap items-end justify-between gap-2">
                        <FieldHelp label="Supplier" :text="help.supplier" />
                        <AppButton
                            type="button"
                            variant="secondary"
                            size="sm"
                            class="mb-1"
                            @click="openNewSupplierForm"
                        >
                            <Plus class="mr-1 h-3.5 w-3.5" />
                            New supplier
                        </AppButton>
                    </div>
                    <AppSelect
                        :model-value="String(form.supplier_id)"
                        :options="supplierSelectOptions"
                        searchable
                        placeholder="Select supplier"
                        search-placeholder="Search suppliers..."
                        @update:model-value="form.supplier_id = Number($event)"
                    />
                    <div v-if="form.supplier_id === 0" class="mt-2 space-y-2">
                        <AppInput
                            v-model="form.supplier_custom"
                            placeholder="One-off supplier name"
                        />
                        <div v-if="canSaveAsSupplier" class="flex flex-wrap items-center gap-2">
                            <AppButton
                                type="button"
                                variant="secondary"
                                size="sm"
                                :loading="saveSupplierLoading"
                                @click="saveAsSupplier"
                            >
                                {{ saveSupplierLoading ? 'Saving…' : 'Save as supplier' }}
                            </AppButton>
                            <p class="text-xs text-slate-500">
                                Keep this vendor for future recurring and one-off expenses.
                            </p>
                        </div>
                        <p v-if="saveSupplierError" class="text-xs text-rose-700">{{ saveSupplierError }}</p>
                    </div>
                    <p v-if="fieldErrors.supplier_id || fieldErrors.supplier" class="mt-1 text-xs text-rose-600">
                        {{ fieldErrors.supplier_id || fieldErrors.supplier }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <FieldHelp label="Category" :text="help.category" />
                        <AppSelect
                            :model-value="form.category_account_id > 0 ? String(form.category_account_id) : ''"
                            :options="categorySelectOptions"
                            searchable
                            placeholder="Select category"
                            search-placeholder="Search categories..."
                            @update:model-value="form.category_account_id = Number($event) || 0"
                        />
                        <p v-if="fieldErrors.category_account_id" class="mt-1 text-xs text-rose-600">
                            {{ fieldErrors.category_account_id }}
                        </p>
                    </div>
                    <div>
                        <FieldHelp label="Paid from" :text="help.paidFrom" />
                        <AppSelect
                            :model-value="form.paid_from_banking_account_id > 0 ? String(form.paid_from_banking_account_id) : ''"
                            :options="paid_from_options.map((option) => ({
                                label: `${option.name} (${option.gl_label})`,
                                value: String(option.id),
                            }))"
                            @update:model-value="form.paid_from_banking_account_id = Number($event)"
                        />
                        <p v-if="fieldErrors.paid_from_banking_account_id" class="mt-1 text-xs text-rose-600">
                            {{ fieldErrors.paid_from_banking_account_id }}
                        </p>
                    </div>
                </div>
                <div>
                    <FieldHelp label="Description" :text="help.description" />
                    <div class="mb-2 flex flex-wrap gap-2">
                        <AppButton
                            v-for="item in placeholderTokens"
                            :key="item.token"
                            size="sm"
                            variant="ghost"
                            type="button"
                            @click="insertToken(item.token)"
                        >
                            {{ item.label }}
                        </AppButton>
                    </div>
                    <AppInput v-model="form.description" placeholder="Rent for {{month_year}}" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <FieldHelp label="Total (incl VAT)" :text="help.amount" />
                        <AppInput
                            :model-value="String(form.amount_incl_vat)"
                            type="text"
                            inputmode="decimal"
                            class="tabular-nums"
                            @update:model-value="form.amount_incl_vat = Number($event || 0)"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">VAT rate</label>
                        <AppSelect
                            :model-value="form.vat_rate"
                            :options="taxRateList.map((rate) => ({ label: rate.label, value: rate.value }))"
                            @update:model-value="form.vat_rate = $event as VatRate"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Amount (excl VAT)</label>
                        <AppInput
                            :model-value="amountExclVatDisplay"
                            type="text"
                            inputmode="decimal"
                            class="tabular-nums"
                            disabled
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">VAT amount</label>
                        <AppInput
                            :model-value="vatAmountDisplay"
                            type="text"
                            inputmode="decimal"
                            class="tabular-nums"
                            disabled
                        />
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Reference</label>
                        <AppInput v-model="form.reference" placeholder="Optional (placeholders ok)" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                        <AppInput v-model="form.notes" placeholder="Optional (placeholders ok)" />
                    </div>
                </div>
            </section>

            <section class="space-y-4 border-t border-slate-100 pt-6">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Schedule</h3>
                    <p class="mt-0.5 text-xs text-slate-500">How often this expense is posted</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <FieldHelp label="Frequency" :text="help.frequency" />
                        <AppSelect
                            :model-value="form.frequency"
                            :options="[
                                { label: 'Weekly', value: 'weekly' },
                                { label: 'Monthly', value: 'monthly' },
                                { label: 'Yearly', value: 'yearly' },
                            ]"
                            @update:model-value="form.frequency = String($event)"
                        />
                    </div>
                    <div>
                        <FieldHelp label="Next run date" :text="help.nextRun" />
                        <AppInput v-model="form.next_run_date" type="date" />
                        <p v-if="fieldErrors.next_run_date" class="mt-1 text-xs text-rose-600">{{ fieldErrors.next_run_date }}</p>
                    </div>
                </div>

                <div v-if="form.frequency === 'weekly'">
                    <FieldHelp label="Generate on weekday" :text="help.weekday" />
                    <AppSelect
                        :model-value="String(form.generate_on_weekday)"
                        :options="[
                            { label: 'Monday', value: '1' },
                            { label: 'Tuesday', value: '2' },
                            { label: 'Wednesday', value: '3' },
                            { label: 'Thursday', value: '4' },
                            { label: 'Friday', value: '5' },
                            { label: 'Saturday', value: '6' },
                            { label: 'Sunday', value: '7' },
                        ]"
                        @update:model-value="form.generate_on_weekday = Number($event)"
                    />
                </div>
                <div v-else class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <FieldHelp label="Generate on day" :text="help.generateDay" />
                        <AppInput
                            v-model="form.generate_on_day"
                            type="number"
                            min="1"
                            max="28"
                            :disabled="form.generate_on_last_day"
                        />
                    </div>
                    <label class="mt-6 flex items-center gap-2 text-sm text-slate-700">
                        <input v-model="form.generate_on_last_day" type="checkbox" class="rounded border-slate-300">
                        Last day of month
                    </label>
                    <div v-if="form.frequency === 'yearly'">
                        <FieldHelp label="Generate on month" :text="help.generateMonth" />
                        <AppInput v-model="form.generate_on_month" type="number" min="1" max="12" />
                    </div>
                </div>

                <div>
                    <FieldHelp label="Period offset (months)" :text="help.periodOffset" />
                    <AppInput v-model="form.period_offset_months" type="number" min="-12" max="12" />
                </div>
            </section>

            <section class="space-y-4 border-t border-slate-100 pt-6">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Limits</h3>
                    <p class="mt-0.5 text-xs text-slate-500">When this schedule should stop</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <FieldHelp label="Limits" :text="help.limits" />
                        <AppSelect
                            :model-value="form.limit_type"
                            :options="[
                                { label: 'None', value: 'none' },
                                { label: 'Count', value: 'count' },
                                { label: 'End date', value: 'end_date' },
                            ]"
                            @update:model-value="form.limit_type = String($event)"
                        />
                    </div>
                    <div v-if="form.limit_type === 'count'">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Limit count</label>
                        <AppInput v-model="form.limit_count" type="number" min="1" />
                    </div>
                    <div v-if="form.limit_type === 'end_date'">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Limit end date</label>
                        <AppInput v-model="form.limit_end_date" type="date" />
                    </div>
                </div>
            </section>

            <FormActions bordered>
                <AppButton
                    variant="primary"
                    :disabled="saving"
                    :loading="saving"
                    @click="submit"
                >
                    {{ isEditing ? 'Update' : 'Save' }}
                </AppButton>
                <AppButton variant="secondary" @click="router.visit(route('expenses.recurring.index'))">
                    Cancel
                </AppButton>
            </FormActions>
        </AppCard>
    </AppLayout>
</template>
