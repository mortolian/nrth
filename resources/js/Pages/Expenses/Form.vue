<script setup lang="ts">
import { computed, reactive, ref, toRaw, watch, withDefaults } from 'vue';
import { router } from '@inertiajs/vue3';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { Camera, Upload } from 'lucide-vue-next';
import { FALLBACK_EXPENSE_TAX_RATES, type ExpenseTaxRateOption } from './fallbackTaxRates';

type CategoryOption = { id: number; name: string };
type SupplierOption = { id: number; name: string };
type TaxRateOption = ExpenseTaxRateOption;

type ExpenseFormRow = {
    id: number;
    date: string | null;
    supplier_id: number;
    supplier_custom: string;
    category_account_id: number;
    description: string;
    amount_excl_vat: number;
    vat_rate: 'vat15' | 'vat0' | 'exempt' | 'no_vat';
    vat_amount: number;
    payment_method: 'business_account' | 'personal_reimbursable' | 'credit_card';
    reference: string;
    notes: string;
    office_percentage: number;
    distance_km: number;
    rate_per_km: number;
};

const props = withDefaults(
    defineProps<{
        isEditing: boolean;
        expense: ExpenseFormRow | null;
        prefill: { supplier_id: number; supplier_custom: string } | null;
        categories: CategoryOption[];
        supplier_options: SupplierOption[];
        tax_rates: TaxRateOption[];
        sars_rate_per_km: number;
    }>(),
    {
        isEditing: false,
        expense: null,
        prefill: null,
        categories: () => [],
        supplier_options: () => [],
        tax_rates: () => FALLBACK_EXPENSE_TAX_RATES,
        sars_rate_per_km: 4.84,
    },
);

const categoryList = computed(() => props.categories);
const supplierList = computed(() => props.supplier_options);
const taxRateList = computed(() => (props.tax_rates?.length ? props.tax_rates : FALLBACK_EXPENSE_TAX_RATES));

const schema = z
    .object({
        date: z.string().min(1),
        supplier_id: z.coerce.number().int().min(0),
        supplier_custom: z.string().optional(),
        category_account_id: z.coerce.number().int().positive(),
        description: z.string().optional(),
        amount_excl_vat: z.coerce.number().min(0),
        vat_rate: z.enum(['vat15', 'vat0', 'exempt', 'no_vat']),
        vat_amount: z.coerce.number().min(0),
        payment_method: z.enum(['business_account', 'personal_reimbursable', 'credit_card']),
        reference: z.string().optional(),
        notes: z.string().optional(),
        office_percentage: z.coerce.number().min(0).max(100).optional(),
        distance_km: z.coerce.number().min(0).optional(),
        rate_per_km: z.coerce.number().min(0).optional(),
    })
    .refine((data) => data.supplier_id > 0 || (data.supplier_custom?.trim().length ?? 0) > 0, {
        path: ['supplier_custom'],
        message: 'Choose a saved supplier or enter a one-off name',
    });

const initialFromProps = () => {
    if (props.isEditing && props.expense) {
        const e = props.expense;
        return {
            date: e.date ?? new Date().toISOString().slice(0, 10),
            supplier_id: e.supplier_id,
            supplier_custom: e.supplier_custom,
            category_account_id: e.category_account_id || (categoryList.value[0]?.id ?? 0),
            description: e.description,
            amount_excl_vat: e.amount_excl_vat,
            vat_rate: e.vat_rate,
            vat_amount: e.vat_amount,
            payment_method: e.payment_method,
            reference: e.reference,
            notes: e.notes,
            office_percentage: e.office_percentage,
            distance_km: e.distance_km,
            rate_per_km: e.rate_per_km || props.sars_rate_per_km,
        };
    }
    const p = props.prefill;
    return {
        date: new Date().toISOString().slice(0, 10),
        supplier_id: p?.supplier_id && p.supplier_id > 0 ? p.supplier_id : 0,
        supplier_custom: p?.supplier_custom ?? '',
        category_account_id: categoryList.value[0]?.id ?? 0,
        description: '',
        amount_excl_vat: 0,
        vat_rate: 'vat15' as const,
        vat_amount: 0,
        payment_method: 'business_account' as const,
        reference: '',
        notes: '',
        office_percentage: 15,
        distance_km: 0,
        rate_per_km: props.sars_rate_per_km,
    };
};

const form = reactive(initialFromProps());

const receiptFile = ref<File | null>(null);
const receiptPreviewUrl = ref<string | null>(null);
const showAdvanced = ref(false);

const selectedTax = computed(
    () => taxRateList.value.find((rate) => rate.value === form.vat_rate) ?? taxRateList.value[0] ?? FALLBACK_EXPENSE_TAX_RATES[0],
);
const vatAutoCents = computed(() => Math.round(Number(form.amount_excl_vat || 0) * Number(selectedTax.value?.rate || 0) * 100));
const totalCents = computed(() => Math.round(Number(form.amount_excl_vat || 0) * 100) + Math.round(Number(form.vat_amount || 0) * 100));
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const selectedCategory = computed(() => {
    const id = Number(form.category_account_id || 0);
    return categoryList.value.find((category) => category.id === id);
});
const isHomeOffice = computed(
    () => selectedCategory.value?.name?.toLowerCase().includes('home office') ?? false,
);
const isTravel = computed(() => selectedCategory.value?.name?.toLowerCase().includes('travel') ?? false);
const travelDeduction = computed(() => Number(form.distance_km || 0) * Number(form.rate_per_km || 0));

watch(
    () => [form.amount_excl_vat, form.vat_rate],
    () => {
        form.vat_amount = vatAutoCents.value / 100;
    },
    { immediate: true },
);

const onReceiptChange = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    receiptFile.value = file;
    if (receiptPreviewUrl.value) {
        URL.revokeObjectURL(receiptPreviewUrl.value);
        receiptPreviewUrl.value = null;
    }
    if (file) {
        receiptPreviewUrl.value = URL.createObjectURL(file);
    }
};

const supplierSelectOptions = computed(() => [
    { label: 'Custom (one-off)', value: '0' },
    ...supplierList.value.map((s) => ({ label: s.name, value: String(s.id) })),
]);

const categorySelectOptions = computed(() =>
    categoryList.value.map((category) => ({ label: category.name, value: String(category.id) })),
);

const taxRateSelectOptions = computed(() =>
    taxRateList.value.map((rate) => ({ label: rate.label, value: rate.value })),
);

const hasCategories = computed(() => categoryList.value.length > 0);

const buildFormData = (parsed: z.infer<typeof schema>) => {
    const form = new FormData();
    form.set('date', parsed.date);
    if (parsed.supplier_id > 0) {
        form.set('supplier_id', String(parsed.supplier_id));
    } else {
        form.set('supplier', parsed.supplier_custom?.trim() ?? '');
    }
    form.set('category_account_id', String(parsed.category_account_id));
    form.set('description', parsed.description ?? '');
    form.set('amount_excl_vat_cents', String(Math.round(parsed.amount_excl_vat * 100)));
    form.set('vat_rate', parsed.vat_rate);
    form.set('vat_amount_cents', String(Math.round(parsed.vat_amount * 100)));
    form.set('payment_method', parsed.payment_method);
    form.set('reference', parsed.reference ?? '');
    form.set('notes', parsed.notes ?? '');
    if (isHomeOffice.value) form.set('office_percentage', String(parsed.office_percentage ?? 0));
    if (isTravel.value) {
        form.set('distance_km', String(parsed.distance_km ?? 0));
        form.set('rate_per_km', String(parsed.rate_per_km ?? props.sars_rate_per_km));
    }
    if (receiptFile.value) form.set('receipt', receiptFile.value);
    return form;
};

const submit = () => {
    if (!hasCategories.value) return;
    const parsed = schema.safeParse(toRaw(form));
    if (!parsed.success) return;

    const form = buildFormData(parsed.data);
    if (props.isEditing && props.expense) {
        form.append('_method', 'put');
        router.post(route('expenses.update', props.expense.id), form);
        return;
    }
    router.post(route('expenses.store'), form);
};
</script>

<template>
    <AppLayout
        :title="props.isEditing ? 'Edit Expense' : 'New Expense'"
        :breadcrumbs="[
            { label: 'Money Out' },
            { label: 'Expenses', href: route('expenses.index') },
            { label: props.isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader :title="props.isEditing ? 'Edit Expense' : 'Create Expense'" subtitle="Capture expense details and post journal entries" />

        <AppCard v-if="!hasCategories" class="mt-5">
            <p class="text-sm text-slate-700">Add at least one active expense category in your chart of accounts before recording expenses.</p>
            <AppButton variant="primary" class="mt-3" @click="router.visit(route('accounting.accounts.index'))">Chart of accounts</AppButton>
        </AppCard>

        <AppCard v-else class="mt-5">
            <div class="mb-5 flex flex-col gap-3 sm:flex-row md:mb-6">
                <label
                    class="flex min-h-12 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-brand-500 bg-brand-50 px-4 py-3 text-sm font-semibold text-brand-900 shadow-sm active:bg-brand-100"
                >
                    <Camera class="h-5 w-5 shrink-0" />
                    Take photo
                    <input type="file" accept="image/*" capture="environment" class="hidden" @change="onReceiptChange">
                </label>
                <label
                    class="flex min-h-12 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-800 active:bg-slate-50"
                >
                    <Upload class="h-5 w-5 shrink-0" />
                    Gallery / PDF
                    <input type="file" accept="image/*,.pdf" class="hidden" @change="onReceiptChange">
                </label>
            </div>

            <div v-if="receiptFile" class="mb-5 rounded-md border border-slate-200 p-3">
                <p class="text-xs text-slate-500">{{ receiptFile.name }}</p>
                <img v-if="receiptPreviewUrl && receiptFile.type.startsWith('image/')" :src="receiptPreviewUrl" alt="Receipt preview" class="mt-2 max-h-44 rounded">
                <div v-else class="mt-2 inline-flex items-center gap-2 text-sm text-slate-600">
                    <Camera class="h-4 w-4" />
                    PDF ready for upload
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Date</label>
                    <AppInput v-model="form.date" type="date" class="min-h-12 text-base md:min-h-0 md:text-sm" />
                </div>
                <div class="md:col-span-2">
                    <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                        <label class="block text-xs font-medium text-slate-500">Supplier</label>
                        <button
                            type="button"
                            class="text-xs font-medium text-brand-600 hover:underline"
                            @click="router.get(route('suppliers.create'), { return: props.isEditing && props.expense ? `/expenses/${props.expense.id}/edit` : '/expenses/create' })"
                        >
                            New supplier
                        </button>
                    </div>
                    <AppSelect
                        :model-value="String(form.supplier_id)"
                        :options="supplierSelectOptions"
                        @update:model-value="form.supplier_id = Number($event)"
                    />
                    <AppInput
                        v-if="form.supplier_id === 0"
                        v-model="form.supplier_custom"
                        placeholder="One-off supplier name"
                        class="mt-2 min-h-12 text-base md:min-h-0 md:text-sm"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Category</label>
                    <AppSelect
                        :model-value="String(form.category_account_id)"
                        :options="categorySelectOptions"
                        @update:model-value="form.category_account_id = Number($event)"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Amount (excl VAT)</label>
                    <AppInput
                        v-model="form.amount_excl_vat"
                        type="text"
                        inputmode="decimal"
                        class="min-h-12 text-base md:min-h-0 md:text-sm"
                    />
                    <p v-if="isTravel" class="mt-1 text-xs text-amber-700">Travel uses distance × rate for the posted amount; this field is ignored when you save.</p>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Description <span class="font-normal text-slate-400">(optional)</span></label>
                    <AppInput v-model="form.description" placeholder="What was purchased?" class="min-h-12 text-base md:min-h-0 md:text-sm" />
                </div>
            </div>

            <button
                type="button"
                class="mt-4 flex w-full min-h-12 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-slate-50 text-sm font-medium text-slate-800 md:hidden"
                @click="showAdvanced = !showAdvanced"
            >
                {{ showAdvanced ? 'Hide' : 'More options' }}
                <span class="text-xs text-slate-500">(VAT, payment, notes)</span>
            </button>

            <div :class="['mt-4 grid gap-4 md:grid-cols-2', !showAdvanced && 'max-md:hidden']">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">VAT rate</label>
                    <AppSelect
                        :model-value="form.vat_rate"
                        :options="taxRateSelectOptions"
                        @update:model-value="form.vat_rate = $event as 'vat15' | 'vat0' | 'exempt' | 'no_vat'"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">VAT amount (override)</label>
                    <AppInput v-model="form.vat_amount" type="text" inputmode="decimal" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Total (incl VAT)</label>
                    <AppInput :model-value="formatCents(totalCents)" disabled />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Payment method</label>
                    <AppSelect
                        :model-value="form.payment_method"
                        :options="[
                            { label: 'Business account', value: 'business_account' },
                            { label: 'Personal reimbursable', value: 'personal_reimbursable' },
                            { label: 'Credit card', value: 'credit_card' },
                        ]"
                        @update:model-value="form.payment_method = $event as 'business_account' | 'personal_reimbursable' | 'credit_card'"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Reference</label>
                    <AppInput v-model="form.reference" placeholder="Invoice / order #" />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                    <textarea v-model="form.notes" class="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                </div>
            </div>

            <div v-if="isHomeOffice" class="mt-5 rounded-md border border-slate-200 p-4">
                <p class="text-sm font-semibold text-slate-900">Home Office Details</p>
                <label class="mt-2 block text-xs font-medium text-slate-500">Office percentage: {{ form.office_percentage }}%</label>
                <input v-model.number="form.office_percentage" type="range" min="0" max="100" class="mt-2 w-full">
            </div>

            <div v-if="isTravel" class="mt-5 rounded-md border border-slate-200 p-4">
                <p class="text-sm font-semibold text-slate-900">Travel Details</p>
                <div class="mt-2 grid gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Distance (km)</label>
                        <AppInput v-model="form.distance_km" type="number" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Rate per km</label>
                        <AppInput v-model="form.rate_per_km" type="number" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Calculated deduction</label>
                        <AppInput :model-value="useFormatCurrency(travelDeduction, 'ZAR')" disabled />
                    </div>
                </div>
                <p class="mt-2 text-xs text-amber-700">Keep logbook for SARS compliance.</p>
            </div>

            <div class="mt-5 hidden rounded-md border border-dashed border-slate-300 p-4 md:block">
                <p class="text-sm font-semibold text-slate-900">Receipt (desktop)</p>
                <label class="mt-2 flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-4 text-sm text-slate-600 hover:bg-slate-100">
                    <Upload class="h-4 w-4" />
                    Drag and drop or click to upload
                    <input type="file" accept="image/*,.pdf" class="hidden" @change="onReceiptChange">
                </label>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <AppButton variant="ghost" size="touch" class="w-full sm:w-auto sm:min-h-0 sm:px-4 sm:py-2 sm:text-sm" @click="router.visit(route('expenses.index'))">Cancel</AppButton>
                <AppButton variant="primary" size="touch" class="w-full sm:w-auto sm:min-h-0 sm:px-4 sm:py-2 sm:text-sm" @click="submit">
                    {{ props.isEditing ? 'Update Expense' : 'Save Expense' }}
                </AppButton>
            </div>
        </AppCard>
    </AppLayout>
</template>
