<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, toRaw, watch, withDefaults } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { Camera, ScanLine, Upload, X } from 'lucide-vue-next';
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

const page = usePage();
const aiEnabled = computed(() => Boolean(page.props.ai_enabled));
const scanReceiptLoading = ref(false);
const scanReceiptError = ref<string | null>(null);
const scanReceiptApplied = ref(false);

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

const receiptFiles = ref<File[]>([]);
const receiptPreviewUrls = ref<string[]>([]);
const receiptPreviewIndex = ref<number | null>(null);
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

const isImageFile = (file: File) => file.type.startsWith('image/');
const isPdfFile = (file: File) =>
    file.type === 'application/pdf' || /\.pdf$/i.test(file.name);

const previewUrlFor = (file: File) => {
    if (isImageFile(file) || isPdfFile(file)) {
        return URL.createObjectURL(file);
    }
    return '';
};

const onReceiptChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const incoming = Array.from(input.files ?? []);
    if (!incoming.length) return;

    const nextFiles = [...receiptFiles.value, ...incoming].slice(0, 20);
    receiptPreviewUrls.value.forEach((url) => {
        if (url) URL.revokeObjectURL(url);
    });
    receiptFiles.value = nextFiles;
    receiptPreviewUrls.value = nextFiles.map((file) => previewUrlFor(file));
    scanReceiptError.value = null;
    scanReceiptApplied.value = false;
    input.value = '';
};

const removeReceiptAt = (index: number) => {
    if (receiptPreviewIndex.value === index) {
        receiptPreviewIndex.value = null;
    } else if (receiptPreviewIndex.value !== null && receiptPreviewIndex.value > index) {
        receiptPreviewIndex.value -= 1;
    }
    const url = receiptPreviewUrls.value[index];
    if (url) URL.revokeObjectURL(url);
    receiptFiles.value = receiptFiles.value.filter((_, i) => i !== index);
    receiptPreviewUrls.value = receiptPreviewUrls.value.filter((_, i) => i !== index);
};

const clearReceipts = () => {
    receiptPreviewIndex.value = null;
    receiptPreviewUrls.value.forEach((url) => {
        if (url) URL.revokeObjectURL(url);
    });
    receiptFiles.value = [];
    receiptPreviewUrls.value = [];
    scanReceiptApplied.value = false;
    scanReceiptError.value = null;
};

const openReceiptPreview = (index: number) => {
    if (!receiptPreviewUrls.value[index]) {
        return;
    }
    receiptPreviewIndex.value = index;
};

const closeReceiptPreview = () => {
    receiptPreviewIndex.value = null;
};

const previewedReceipt = computed(() => {
    const index = receiptPreviewIndex.value;
    if (index === null) {
        return null;
    }
    const file = receiptFiles.value[index];
    const url = receiptPreviewUrls.value[index];
    if (!file || !url) {
        return null;
    }

    return { file, url, index };
});

watch(receiptPreviewIndex, (index, _prev, onCleanup) => {
    if (index === null) {
        return;
    }

    const onKeydown = (event: KeyboardEvent) => {
        if (event.key === 'Escape') {
            closeReceiptPreview();
        }
    };
    window.addEventListener('keydown', onKeydown);
    onCleanup(() => window.removeEventListener('keydown', onKeydown));
});

onUnmounted(() => {
    receiptPreviewIndex.value = null;
    receiptPreviewUrls.value.forEach((url) => {
        if (url) URL.revokeObjectURL(url);
    });
});

const scanReceipt = async () => {
    if (!aiEnabled.value || !receiptFiles.value.length || scanReceiptLoading.value) {
        return;
    }

    const token = page.props.csrf_token as string | undefined;
    if (!token) {
        scanReceiptError.value = 'Unable to scan: missing security token. Refresh the page and try again.';
        return;
    }

    scanReceiptLoading.value = true;
    scanReceiptError.value = null;
    scanReceiptApplied.value = false;

    try {
        const body = new FormData();
        body.append('receipt', receiptFiles.value[0]);

        const res = await fetch(route('expenses.parse-receipt'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
            },
            body,
        });

        const payload = (await res.json().catch(() => null)) as {
            data?: {
                date?: string | null;
                supplier_id?: number;
                supplier?: string;
                description?: string;
                amount_excl_vat?: number | null;
                vat_amount?: number | null;
                vat_rate?: 'vat15' | 'vat0' | 'exempt' | 'no_vat' | null;
                reference?: string;
            };
            message?: string;
            errors?: Record<string, string[]>;
        } | null;

        if (!res.ok) {
            const firstError = payload?.errors
                ? Object.values(payload.errors).flat()[0]
                : null;
            scanReceiptError.value = firstError || payload?.message || 'Could not scan this receipt.';
            return;
        }

        const data = payload?.data;
        if (!data) {
            scanReceiptError.value = 'Could not scan this receipt.';
            return;
        }

        if (data.date) form.date = data.date;
        if (typeof data.supplier_id === 'number' && data.supplier_id > 0) {
            form.supplier_id = data.supplier_id;
            form.supplier_custom = '';
        } else if (data.supplier?.trim()) {
            form.supplier_id = 0;
            form.supplier_custom = data.supplier.trim();
        }
        if (data.description != null) form.description = data.description;
        if (data.amount_excl_vat != null) form.amount_excl_vat = data.amount_excl_vat;
        if (data.vat_amount != null) form.vat_amount = data.vat_amount;
        if (data.vat_rate) form.vat_rate = data.vat_rate;
        if (data.reference != null) form.reference = data.reference;

        scanReceiptApplied.value = true;
    } catch {
        scanReceiptError.value = 'Could not reach the scanning service. Try again.';
    } finally {
        scanReceiptLoading.value = false;
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
    receiptFiles.value.forEach((file, index) => {
        form.append(`receipts[${index}]`, file);
    });
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
        <PageHeader :title="props.isEditing ? 'Edit Expense' : 'Create Expense'" />

        <AppCard v-if="!hasCategories" class="mt-5">
            <p class="text-sm text-slate-700">Add at least one active expense category in your chart of accounts before recording expenses.</p>
            <AppButton variant="primary" class="mt-3" @click="router.visit(route('accounting.accounts.index'))">Chart of accounts</AppButton>
        </AppCard>

        <AppCard v-else class="mt-5">
            <div class="mb-5">
                <div class="mb-1 flex items-center justify-between gap-3">
                    <p class="text-xs font-medium text-slate-500">Receipts</p>
                    <button
                        v-if="receiptFiles.length"
                        type="button"
                        class="text-xs font-medium text-slate-500 hover:text-slate-800"
                        @click="clearReceipts"
                    >
                        Remove all
                    </button>
                </div>
                <label
                    class="flex min-h-16 cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-center transition hover:border-slate-400 hover:bg-slate-100"
                >
                    <Upload class="h-4 w-4 shrink-0 text-slate-500" />
                    <span class="text-sm font-medium text-slate-800">
                        {{ receiptFiles.length ? 'Add more' : 'Upload receipts' }}
                    </span>
                    <span class="hidden text-xs text-slate-500 sm:inline">Photos or PDFs</span>
                    <input type="file" accept="image/*,.pdf" multiple class="hidden" @change="onReceiptChange">
                </label>

                <div
                    v-if="aiEnabled && receiptFiles.length"
                    class="mt-2 flex flex-wrap items-center gap-2"
                >
                    <AppButton
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="scanReceiptLoading"
                        @click="scanReceipt"
                    >
                        <ScanLine class="mr-1.5 h-4 w-4" />
                        {{ scanReceiptLoading ? 'Scanning…' : 'Scan receipt' }}
                    </AppButton>
                    <p class="text-xs text-slate-500">Uses the first file to fill date, supplier, amounts, and reference.</p>
                </div>
                <p v-if="scanReceiptApplied" class="mt-2 text-xs text-emerald-700">
                    Applied from receipt — review the fields before saving.
                </p>
                <p v-if="scanReceiptError" class="mt-2 text-xs text-rose-700">
                    {{ scanReceiptError }}
                </p>

                <ul v-if="receiptFiles.length" class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5">
                    <li
                        v-for="(file, index) in receiptFiles"
                        :key="`${file.name}-${file.size}-${index}`"
                        class="group relative"
                    >
                        <button
                            type="button"
                            class="aspect-square w-full overflow-hidden rounded-lg border border-slate-200 bg-slate-100 text-left transition hover:border-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                            :aria-label="`Preview ${file.name}`"
                            :disabled="!receiptPreviewUrls[index]"
                            @click="openReceiptPreview(index)"
                        >
                            <img
                                v-if="isImageFile(file) && receiptPreviewUrls[index]"
                                :src="receiptPreviewUrls[index]"
                                :alt="file.name"
                                class="h-full w-full object-cover"
                            >
                            <div
                                v-else-if="isPdfFile(file) && receiptPreviewUrls[index]"
                                class="relative h-full w-full overflow-hidden bg-white"
                            >
                                <iframe
                                    :src="`${receiptPreviewUrls[index]}#toolbar=0&navpanes=0&scrollbar=0&view=FitH`"
                                    class="pointer-events-none absolute inset-0 h-[180%] w-[180%] origin-top-left scale-[0.56] border-0"
                                    tabindex="-1"
                                    :title="`Preview of ${file.name}`"
                                />
                                <span class="pointer-events-none absolute bottom-1 left-1 rounded bg-slate-900/75 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-white">
                                    PDF
                                </span>
                            </div>
                            <div
                                v-else
                                class="flex h-full w-full flex-col items-center justify-center gap-1 px-2 text-slate-500"
                            >
                                <Camera class="h-5 w-5" />
                                <span class="text-[10px] font-medium uppercase tracking-wide">File</span>
                            </div>
                        </button>
                        <p class="mt-1 truncate text-[11px] text-slate-600" :title="file.name">{{ file.name }}</p>
                        <button
                            type="button"
                            class="absolute right-1 top-1 inline-flex h-6 w-6 items-center justify-center rounded-full bg-white/95 text-slate-600 shadow-sm ring-1 ring-slate-200 hover:bg-white hover:text-slate-900"
                            :aria-label="`Remove ${file.name}`"
                            @click.stop="removeReceiptAt(index)"
                        >
                            <span class="text-sm leading-none" aria-hidden="true">×</span>
                        </button>
                    </li>
                </ul>
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

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <AppButton variant="ghost" size="touch" class="w-full sm:w-auto sm:min-h-0 sm:px-4 sm:py-2 sm:text-sm" @click="router.visit(route('expenses.index'))">Cancel</AppButton>
                <AppButton variant="primary" size="touch" class="w-full sm:w-auto sm:min-h-0 sm:px-4 sm:py-2 sm:text-sm" @click="submit">
                    {{ props.isEditing ? 'Update Expense' : 'Save Expense' }}
                </AppButton>
            </div>
        </AppCard>

        <Teleport to="body">
            <div
                v-if="previewedReceipt"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/70 p-4"
                role="dialog"
                aria-modal="true"
                :aria-label="`Preview ${previewedReceipt.file.name}`"
                @click.self="closeReceiptPreview"
            >
                <div class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                        <p class="truncate text-sm font-medium text-slate-900" :title="previewedReceipt.file.name">
                            {{ previewedReceipt.file.name }}
                        </p>
                        <button
                            type="button"
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                            aria-label="Close preview"
                            @click="closeReceiptPreview"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="flex min-h-0 flex-1 items-center justify-center bg-slate-50 p-3 sm:p-4">
                        <img
                            v-if="isImageFile(previewedReceipt.file)"
                            :src="previewedReceipt.url"
                            :alt="previewedReceipt.file.name"
                            class="max-h-[75vh] max-w-full rounded object-contain"
                        >
                        <iframe
                            v-else-if="isPdfFile(previewedReceipt.file)"
                            :src="previewedReceipt.url"
                            class="h-[75vh] w-full rounded border-0 bg-white"
                            :title="previewedReceipt.file.name"
                        />
                        <p v-else class="text-sm text-slate-500">Preview is not available for this file type.</p>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
