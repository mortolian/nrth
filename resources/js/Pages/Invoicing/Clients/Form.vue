<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppPhoneInput from '@/Components/AppPhoneInput.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useToast } from '@/Composables/useToast';
const props = defineProps<{
    isEditing: boolean;
    /** When set (e.g. from invoice create), redirect here after successful create. */
    return_to?: string | null;
    note_templates: Array<{ id: number; name: string; target: 'notes' | 'footer' }>;
    client: null | {
        id: number;
        name: string;
        contact_name: string | null;
        email: string | null;
        phone: string | null;
        vat_number: string | null;
        registration_number: string | null;
        address: {
            street?: string;
            city?: string;
            province?: string;
            postal_code?: string;
            country?: string;
        } | null;
        currency: string;
        payment_terms_days: number;
        notes: string | null;
        is_active: boolean;
        note_template_ids: number[];
    };
}>();

const page = usePage();
const toast = useToast();
const saving = ref(false);
const { fieldErrors, setFromZod, setFromServer, clear, clearField } = useFieldErrors();
const currencyOptions = computed(
    () => (page.props.currencyOptions as Array<{ value: string; label: string }>) ?? [],
);

const { values, setFieldValue: setVeeFieldValue } = useForm({
    initialValues: {
        name: props.client?.name ?? '',
        contact_name: props.client?.contact_name ?? '',
        email: props.client?.email ?? '',
        phone: props.client?.phone ?? '',
        vat_number: props.client?.vat_number ?? '',
        registration_number: props.client?.registration_number ?? '',
        address: {
            street: props.client?.address?.street ?? '',
            city: props.client?.address?.city ?? '',
            province: props.client?.address?.province ?? '',
            postal_code: props.client?.address?.postal_code ?? '',
            country: props.client?.address?.country ?? 'South Africa',
        },
        currency: props.client?.currency ?? 'ZAR',
        payment_terms_days: props.client?.payment_terms_days ?? 30,
        notes: props.client?.notes ?? '',
        is_active: props.client?.is_active ?? true,
        note_template_ids: [...(props.client?.note_template_ids ?? [])],
    },
});

const setFieldValue = (path: string, value: unknown) => {
    setVeeFieldValue(path, value);
    clearField(path);
};

/**
 * vee-validate `values` may be exposed as either a reactive object or a ref-like wrapper.
 * Normalize access so submit works reliably across both shapes.
 */
const formValues = computed<Record<string, any>>(() => ((values as any)?.value ?? values) as Record<string, any>);

const noteTemplatesByTarget = computed(() => ({
    notes: props.note_templates.filter((template) => template.target === 'notes'),
    footer: props.note_templates.filter((template) => template.target === 'footer'),
}));

const isNoteTemplateSelected = (id: number) => (formValues.value.note_template_ids ?? []).includes(id);

const toggleNoteTemplate = (id: number) => {
    const current = [...(formValues.value.note_template_ids ?? [])];
    const index = current.indexOf(id);
    if (index >= 0) {
        current.splice(index, 1);
    } else {
        current.push(id);
    }
    setFieldValue('note_template_ids', current);
};

const schema = z.object({
    name: z.string().trim().min(1, 'Company name is required'),
    contact_name: z.string().optional(),
    email: z.string().email('Invalid email').or(z.literal('')),
    phone: z.string().optional(),
    vat_number: z.string().regex(/^$|^4\d{9}$/, 'SA VAT must be 10 digits starting with 4'),
    registration_number: z.string().optional(),
    address: z.object({
        street: z.string().optional(),
        city: z.string().optional(),
        province: z.string().optional(),
        postal_code: z.string().optional(),
        country: z.string().optional(),
    }),
    currency: z
        .string()
        .length(3, 'Select a currency')
        .regex(/^[A-Z]{3}$/, 'Use a 3-letter ISO currency code'),
    payment_terms_days: z.coerce.number().int().min(0, 'Must be 0–365').max(365, 'Must be 0–365'),
    notes: z.string().optional(),
    is_active: z.boolean(),
    note_template_ids: z.array(z.coerce.number().int()).optional(),
});

const submit = () => {
    if (saving.value) return;

    const result = schema.safeParse(formValues.value);
    if (!result.success) {
        setFromZod(result.error);
        return;
    }

    clear();

    const visitOptions = {
        onStart: () => {
            saving.value = true;
        },
        onSuccess: () => {
            toast.success(props.isEditing ? 'Client saved.' : 'Client created.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
            if (!Object.keys(errors).length) {
                toast.error('Could not save this client.');
            }
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (props.isEditing && props.client) {
        router.put(route('invoicing.clients.update', props.client.id), result.data, visitOptions);
        return;
    }
    const payload = props.return_to
        ? { ...result.data, return: props.return_to }
        : result.data;
    router.post(route('invoicing.clients.store'), payload, visitOptions);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit Client' : 'New Client'"
        :breadcrumbs="[
            { label: 'Money In', href: route('invoicing.invoices.index') },
            { label: 'Clients', href: route('invoicing.clients.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader :title="isEditing ? 'Edit Client' : 'Create Client'" subtitle="Manage billing and company profile fields" />

        <AppCard class="mt-5">
            <form class="space-y-0" @submit.prevent="submit">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Company name</label>
                        <AppInput :model-value="values.name" @update:model-value="setFieldValue('name', $event)" />
                        <p v-if="fieldErrors.name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Contact name</label>
                        <AppInput :model-value="values.contact_name" @update:model-value="setFieldValue('contact_name', $event)" />
                        <p v-if="fieldErrors.contact_name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.contact_name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Email</label>
                        <AppInput :model-value="values.email" type="email" @update:model-value="setFieldValue('email', $event)" />
                        <p v-if="fieldErrors.email" class="mt-1 text-xs text-rose-600">{{ fieldErrors.email }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Phone</label>
                        <AppPhoneInput :model-value="values.phone" @update:model-value="setFieldValue('phone', $event ?? '')" />
                        <p v-if="fieldErrors.phone" class="mt-1 text-xs text-rose-600">{{ fieldErrors.phone }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">VAT number</label>
                        <AppInput :model-value="values.vat_number" placeholder="4XXXXXXXXX" @update:model-value="setFieldValue('vat_number', $event)" />
                        <p v-if="fieldErrors.vat_number" class="mt-1 text-xs text-rose-600">{{ fieldErrors.vat_number }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Company registration number</label>
                        <AppInput :model-value="values.registration_number" @update:model-value="setFieldValue('registration_number', $event)" />
                        <p v-if="fieldErrors.registration_number" class="mt-1 text-xs text-rose-600">{{ fieldErrors.registration_number }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <h3 class="mb-2 text-sm font-semibold text-slate-800">Address</h3>
                        <div class="grid gap-3 md:grid-cols-2">
                            <AppInput :model-value="values.address.street" placeholder="Street" @update:model-value="setFieldValue('address.street', $event)" />
                            <AppInput :model-value="values.address.city" placeholder="City" @update:model-value="setFieldValue('address.city', $event)" />
                            <AppInput :model-value="values.address.province" placeholder="Province" @update:model-value="setFieldValue('address.province', $event)" />
                            <AppInput :model-value="values.address.postal_code" placeholder="Postal code" @update:model-value="setFieldValue('address.postal_code', $event)" />
                            <AppInput :model-value="values.address.country" placeholder="Country" @update:model-value="setFieldValue('address.country', $event)" />
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Currency</label>
                        <AppSelect
                            :model-value="values.currency"
                            :options="currencyOptions"
                            @update:model-value="setFieldValue('currency', $event)"
                        />
                        <p v-if="fieldErrors.currency" class="mt-1 text-xs text-rose-600">{{ fieldErrors.currency }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Payment terms (days)</label>
                        <AppInput :model-value="values.payment_terms_days" type="number" @update:model-value="setFieldValue('payment_terms_days', Number($event))" />
                        <p v-if="fieldErrors.payment_terms_days" class="mt-1 text-xs text-rose-600">{{ fieldErrors.payment_terms_days }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                        <textarea
                            :value="values.notes"
                            class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                            @input="setFieldValue('notes', ($event.target as HTMLTextAreaElement).value)"
                        />
                    </div>
                    <div v-if="note_templates.length" class="md:col-span-2">
                        <h3 class="mb-2 text-sm font-semibold text-slate-800">Note templates</h3>
                        <p class="mb-3 text-xs text-slate-500">
                            Attach reusable snippets from Settings. Notes templates apply to invoice notes; footer templates apply to footers and estimate terms.
                        </p>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div v-if="noteTemplatesByTarget.notes.length">
                                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">Notes</p>
                                <div class="space-y-2">
                                    <label
                                        v-for="template in noteTemplatesByTarget.notes"
                                        :key="template.id"
                                        class="flex cursor-pointer items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                                    >
                                        <input
                                            type="checkbox"
                                            class="rounded border-slate-300"
                                            :checked="isNoteTemplateSelected(template.id)"
                                            @change="toggleNoteTemplate(template.id)"
                                        />
                                        <span>{{ template.name }}</span>
                                    </label>
                                </div>
                            </div>
                            <div v-if="noteTemplatesByTarget.footer.length">
                                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">Footer / terms</p>
                                <div class="space-y-2">
                                    <label
                                        v-for="template in noteTemplatesByTarget.footer"
                                        :key="template.id"
                                        class="flex cursor-pointer items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                                    >
                                        <input
                                            type="checkbox"
                                            class="rounded border-slate-300"
                                            :checked="isNoteTemplateSelected(template.id)"
                                            @change="toggleNoteTemplate(template.id)"
                                        />
                                        <span>{{ template.name }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <p v-if="fieldErrors.note_template_ids" class="mt-1 text-xs text-rose-600">{{ fieldErrors.note_template_ids }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                        <AppSelect
                            :model-value="values.is_active ? 'active' : 'inactive'"
                            :options="[{ label: 'Active', value: 'active' }, { label: 'Inactive', value: 'inactive' }]"
                            @update:model-value="setFieldValue('is_active', $event === 'active')"
                        />
                    </div>
                </div>
                <FormActions>
                    <AppButton type="submit" variant="primary" :loading="saving">
                        {{
                            saving
                                ? 'Saving…'
                                : isEditing
                                    ? 'Update Client'
                                    : 'Create Client'
                        }}
                    </AppButton>
                    <AppButton type="button" variant="secondary" @click="router.visit(route('invoicing.clients.index'))">
                        Cancel
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </AppLayout>
</template>
