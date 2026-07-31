<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import FieldHelp from '@/Components/FieldHelp.vue';
import FormValidationBanner from '@/Components/FormValidationBanner.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useToast } from '@/Composables/useToast';

type TaxRateOption = { id: number; name: string; rate: number; is_default: boolean };

const props = defineProps<{
    isEditing: boolean;
    item: null | {
        id: number;
        name: string;
        description: string | null;
        unit: string | null;
        unit_price_cents: number;
        default_vat_rate: number | null;
        is_active: boolean;
    };
    default_vat_rate: number;
    charges_vat: boolean;
    default_currency: string;
    tax_rates: TaxRateOption[];
    item_units: string[];
}>();

const toast = useToast();
const saving = ref(false);
const { fieldErrors, setFromZod, setFromServer, clear, clearField, messages: clientErrorMessages } = useFieldErrors();

const help = {
    name: 'Shown in the item picker and as the default line description if you leave description blank.',
    description: 'Copied onto the invoice or estimate line when you pick this item. You can still edit the line afterward.',
    unit: 'Optional unit from Settings → Business → Items. Display only — quantity stays free on each line.',
    unitPrice: 'Default unit price in your team currency. Snapshotted onto the line when picked.',
    vat: 'Default VAT % for this item (0 = zero-rated). Overridable per line on the invoice.',
    status: 'Inactive items stay in your catalog but are hidden from invoice and estimate pickers.',
};

const initialVatPercent = () => {
    const rate = props.item?.default_vat_rate != null
        ? Number(props.item.default_vat_rate)
        : Number(props.default_vat_rate ?? 0);
    return String(Number((rate * 100).toFixed(4)));
};

const { values, setFieldValue: setVeeFieldValue } = useForm({
    initialValues: {
        name: props.item?.name ?? '',
        description: props.item?.description ?? '',
        unit: props.item?.unit ?? '',
        unit_price: ((props.item?.unit_price_cents ?? 0) / 100).toFixed(2),
        default_vat_rate_percent: initialVatPercent(),
        is_active: props.item?.is_active ?? true,
    },
});

const setFieldValue = (path: string, value: unknown) => {
    setVeeFieldValue(path, value);
    clearField(path);
};

const formValues = computed<Record<string, any>>(() => ((values as any)?.value ?? values) as Record<string, any>);

const unitOptions = computed(() => {
    const options = [
        { label: 'No unit', value: '' },
        ...props.item_units.map((unit) => ({ label: unit, value: unit })),
    ];
    const current = String(formValues.value.unit ?? '');
    if (current !== '' && !options.some((o) => o.value === current)) {
        options.splice(1, 0, { label: `${current} (current)`, value: current });
    }
    return options;
});

const schema = z.object({
    name: z.string().trim().min(1, 'Name is required'),
    description: z.string().optional(),
    unit: z.string().max(32).optional(),
    unit_price: z.coerce.number().min(0, 'Price must be 0 or more'),
    default_vat_rate_percent: z.coerce.number().min(0).max(100),
    is_active: z.boolean(),
});

const cancelUrl = computed(() =>
    props.isEditing && props.item
        ? route('invoicing.items.show', props.item.id)
        : route('invoicing.items.index'),
);

const submit = () => {
    if (saving.value) return;

    const result = schema.safeParse(formValues.value);
    if (!result.success) {
        setFromZod(result.error);
        return;
    }

    clear();

    const payload = {
        name: result.data.name,
        description: result.data.description || null,
        unit: result.data.unit || null,
        unit_price_cents: Math.round(Number(result.data.unit_price) * 100),
        default_vat_rate: props.charges_vat
            ? Math.round((Number(result.data.default_vat_rate_percent) / 100) * 10000) / 10000
            : null,
        is_active: result.data.is_active,
    };

    const visitOptions = {
        onStart: () => {
            saving.value = true;
        },
        onFinish: () => {
            saving.value = false;
        },
        onSuccess: () => {
            toast.success(props.isEditing ? 'Item saved.' : 'Item created.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
        },
    };

    if (props.isEditing && props.item) {
        router.put(route('invoicing.items.update', props.item.id), payload, visitOptions);
    } else {
        router.post(route('invoicing.items.store'), payload, visitOptions);
    }
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit Item' : 'New Item'"
        :breadcrumbs="[
            { label: 'Money In', href: route('invoicing.invoices.index') },
            { label: 'Items', href: route('invoicing.items.index') },
            { label: isEditing ? 'Edit' : 'New' },
        ]"
    >
        <PageHeader
            :title="isEditing ? 'Edit item' : 'New item'"
            subtitle="Reusable catalog entry for invoice and estimate lines"
        />

        <FormValidationBanner class="mt-5" :errors="clientErrorMessages" />

        <AppCard class="mt-5">
            <form class="space-y-0" @submit.prevent="submit">
                <section>
                    <h3 class="text-sm font-semibold text-slate-900">Details</h3>
                    <p class="mt-0.5 text-xs text-slate-500">How this item appears in pickers and on lines</p>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <FieldHelp label="Name" :text="help.name" />
                            <AppInput
                                :model-value="formValues.name"
                                required
                                placeholder="e.g. Monthly retainer"
                                @update:model-value="setFieldValue('name', $event)"
                            />
                            <p v-if="fieldErrors.name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name }}</p>
                        </div>

                        <div class="md:col-span-2">
                            <FieldHelp label="Description" :text="help.description" />
                            <textarea
                                :value="formValues.description"
                                rows="3"
                                placeholder="Optional default line text"
                                class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25"
                                @input="setFieldValue('description', ($event.target as HTMLTextAreaElement).value)"
                            />
                        </div>

                        <div>
                            <FieldHelp label="Unit" :text="help.unit" />
                            <AppSelect
                                :model-value="formValues.unit ?? ''"
                                :options="unitOptions"
                                @update:model-value="setFieldValue('unit', String($event ?? ''))"
                            />
                            <p v-if="fieldErrors.unit" class="mt-1 text-xs text-rose-600">{{ fieldErrors.unit }}</p>
                        </div>

                        <div>
                            <FieldHelp label="Status" :text="help.status" />
                            <AppSelect
                                :model-value="formValues.is_active ? 'active' : 'inactive'"
                                :options="[
                                    { label: 'Active', value: 'active' },
                                    { label: 'Inactive', value: 'inactive' },
                                ]"
                                @update:model-value="setFieldValue('is_active', $event === 'active')"
                            />
                        </div>
                    </div>
                </section>

                <section class="mt-8 border-t border-slate-100 pt-6">
                    <h3 class="text-sm font-semibold text-slate-900">Pricing</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Defaults in {{ default_currency }} — snapshotted when the item is picked
                    </p>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <FieldHelp :label="`Unit price (${default_currency})`" :text="help.unitPrice" />
                            <AppInput
                                :model-value="formValues.unit_price"
                                type="number"
                                inputmode="decimal"
                                step="0.01"
                                min="0"
                                class="tabular-nums"
                                @update:model-value="setFieldValue('unit_price', $event)"
                            />
                            <p v-if="fieldErrors.unit_price" class="mt-1 text-xs text-rose-600">{{ fieldErrors.unit_price }}</p>
                        </div>

                        <div v-if="charges_vat">
                            <FieldHelp label="Default VAT (%)" :text="help.vat" />
                            <div class="flex max-w-xs items-center gap-2">
                                <AppInput
                                    :model-value="formValues.default_vat_rate_percent"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.01"
                                    min="0"
                                    max="100"
                                    class="tabular-nums"
                                    @update:model-value="setFieldValue('default_vat_rate_percent', $event)"
                                />
                                <span class="text-sm text-slate-500">%</span>
                            </div>
                            <p v-if="fieldErrors.default_vat_rate_percent" class="mt-1 text-xs text-rose-600">
                                {{ fieldErrors.default_vat_rate_percent }}
                            </p>
                        </div>
                    </div>
                </section>

                <FormActions bordered>
                    <AppButton type="submit" variant="primary" :loading="saving" :disabled="saving">
                        {{
                            saving
                                ? 'Saving…'
                                : isEditing
                                    ? 'Update item'
                                    : 'Create item'
                        }}
                    </AppButton>
                    <AppButton type="button" variant="secondary" @click="router.visit(cancelUrl)">
                        Cancel
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </AppLayout>
</template>
