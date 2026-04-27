<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { Camera, Upload } from 'lucide-vue-next';

type CategoryOption = { id: number; name: string };
type TaxRateOption = { value: string; label: string; rate: number; claimable: boolean };

const props = defineProps<{
    categories: CategoryOption[];
    suppliers: string[];
    tax_rates: TaxRateOption[];
    sars_rate_per_km: number;
}>();

const schema = z.object({
    date: z.string().min(1),
    supplier: z.string().min(1),
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
});

const { values } = useForm({
    initialValues: {
        date: new Date().toISOString().slice(0, 10),
        supplier: '',
        category_account_id: props.categories[0]?.id ?? 0,
        description: '',
        amount_excl_vat: 0,
        vat_rate: 'vat15',
        vat_amount: 0,
        payment_method: 'business_account',
        reference: '',
        notes: '',
        office_percentage: 15,
        distance_km: 0,
        rate_per_km: props.sars_rate_per_km,
    },
});

const receiptFile = ref<File | null>(null);
const receiptPreviewUrl = ref<string | null>(null);
/** On small screens, extra fields stay behind “More options”. */
const showAdvanced = ref(false);

const selectedTax = computed(() => props.tax_rates.find((rate) => rate.value === values.value.vat_rate) ?? props.tax_rates[0]);
const vatAutoCents = computed(() => Math.round(Number(values.value.amount_excl_vat || 0) * Number(selectedTax.value?.rate || 0) * 100));
const totalCents = computed(() => Math.round(Number(values.value.amount_excl_vat || 0) * 100) + Math.round(Number(values.value.vat_amount || 0) * 100));
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const selectedCategory = computed(() => {
    const id = Number(values.value.category_account_id || 0);
    return props.categories.find((category) => category.id === id);
});
const isHomeOffice = computed(() => selectedCategory.value?.name.toLowerCase().includes('home office') ?? false);
const isTravel = computed(() => selectedCategory.value?.name.toLowerCase().includes('travel') ?? false);
const travelDeduction = computed(() => Number(values.value.distance_km || 0) * Number(values.value.rate_per_km || 0));

watch(
    () => [values.value.amount_excl_vat, values.value.vat_rate],
    () => {
        values.value.vat_amount = vatAutoCents.value / 100;
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

const submit = () => {
    const parsed = schema.safeParse(values.value);
    if (!parsed.success) return;

    const form = new FormData();
    form.set('date', parsed.data.date);
    form.set('supplier', parsed.data.supplier);
    form.set('category_account_id', String(parsed.data.category_account_id));
    form.set('description', parsed.data.description ?? '');
    form.set('amount_excl_vat_cents', String(Math.round(parsed.data.amount_excl_vat * 100)));
    form.set('vat_rate', parsed.data.vat_rate);
    form.set('vat_amount_cents', String(Math.round(parsed.data.vat_amount * 100)));
    form.set('payment_method', parsed.data.payment_method);
    form.set('reference', parsed.data.reference ?? '');
    form.set('notes', parsed.data.notes ?? '');
    if (isHomeOffice.value) form.set('office_percentage', String(parsed.data.office_percentage ?? 0));
    if (isTravel.value) {
        form.set('distance_km', String(parsed.data.distance_km ?? 0));
        form.set('rate_per_km', String(parsed.data.rate_per_km ?? props.sars_rate_per_km));
    }
    if (receiptFile.value) form.set('receipt', receiptFile.value);

    router.post(route('expenses.store'), form);
};
</script>

<template>
    <AppLayout
        title="New Expense"
        :breadcrumbs="[
            { label: 'Money Out' },
            { label: 'Expenses', href: route('expenses.index') },
            { label: 'Create' },
        ]"
    >
        <PageHeader title="Create Expense" subtitle="Capture expense details and post journal entries" />

        <AppCard class="mt-5">
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
                    <AppInput v-model="values.date" type="date" class="min-h-12 text-base md:min-h-0 md:text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Supplier</label>
                    <AppInput v-model="values.supplier" list="supplier-options" placeholder="Search or create supplier..." class="min-h-12 text-base md:min-h-0 md:text-sm" />
                    <datalist id="supplier-options">
                        <option v-for="supplier in suppliers" :key="supplier" :value="supplier" />
                    </datalist>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Category</label>
                    <AppSelect
                        :model-value="String(values.category_account_id)"
                        :options="categories.map((category) => ({ label: category.name, value: String(category.id) }))"
                        @update:model-value="values.category_account_id = Number($event)"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Amount (excl VAT)</label>
                    <AppInput
                        v-model="values.amount_excl_vat"
                        type="text"
                        inputmode="decimal"
                        class="min-h-12 text-base md:min-h-0 md:text-sm"
                    />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Description <span class="font-normal text-slate-400">(optional)</span></label>
                    <AppInput v-model="values.description" placeholder="What was purchased?" class="min-h-12 text-base md:min-h-0 md:text-sm" />
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
                        :model-value="values.vat_rate"
                        :options="tax_rates.map((rate) => ({ label: rate.label, value: rate.value }))"
                        @update:model-value="values.vat_rate = $event as 'vat15' | 'vat0' | 'exempt' | 'no_vat'"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">VAT amount (override)</label>
                    <AppInput v-model="values.vat_amount" type="text" inputmode="decimal" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Total (incl VAT)</label>
                    <AppInput :model-value="formatCents(totalCents)" disabled />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Payment method</label>
                    <AppSelect
                        :model-value="values.payment_method"
                        :options="[
                            { label: 'Business account', value: 'business_account' },
                            { label: 'Personal reimbursable', value: 'personal_reimbursable' },
                            { label: 'Credit card', value: 'credit_card' },
                        ]"
                        @update:model-value="values.payment_method = $event as 'business_account' | 'personal_reimbursable' | 'credit_card'"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Reference</label>
                    <AppInput v-model="values.reference" />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                    <textarea v-model="values.notes" class="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                </div>
            </div>

            <div v-if="isHomeOffice" class="mt-5 rounded-md border border-slate-200 p-4">
                <p class="text-sm font-semibold text-slate-900">Home Office Details</p>
                <label class="mt-2 block text-xs font-medium text-slate-500">Office percentage: {{ values.office_percentage }}%</label>
                <input v-model="values.office_percentage" type="range" min="0" max="100" class="mt-2 w-full">
            </div>

            <div v-if="isTravel" class="mt-5 rounded-md border border-slate-200 p-4">
                <p class="text-sm font-semibold text-slate-900">Travel Details</p>
                <div class="mt-2 grid gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Distance (km)</label>
                        <AppInput v-model="values.distance_km" type="number" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Rate per km</label>
                        <AppInput v-model="values.rate_per_km" type="number" />
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
                <AppButton variant="primary" size="touch" class="w-full sm:w-auto sm:min-h-0 sm:px-4 sm:py-2 sm:text-sm" @click="submit">Save Expense</AppButton>
            </div>
        </AppCard>
    </AppLayout>
</template>
