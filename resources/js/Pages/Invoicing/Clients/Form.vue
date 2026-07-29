<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppPhoneInput from '@/Components/AppPhoneInput.vue';
import MarkdownEditor from '@/Components/MarkdownEditor.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useToast } from '@/Composables/useToast';

type NoteTemplateOption = { id: number; name: string; body: string; target: 'notes' | 'footer' };

const props = defineProps<{
    isEditing: boolean;
    /** When set (e.g. from invoice create), redirect here after successful create. */
    return_to?: string | null;
    note_templates: NoteTemplateOption[];
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
        default_invoice_notes: string | null;
        is_active: boolean;
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
        default_invoice_notes: props.client?.default_invoice_notes ?? '',
        is_active: props.client?.is_active ?? true,
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

const noteTemplates = computed(() =>
    props.note_templates.filter((template) => template.target === 'notes'),
);

const notesTemplateOptions = computed(() =>
    noteTemplates.value.map((template) => ({
        label: template.name,
        value: String(template.id),
    })),
);

const insertNoteTemplate = (templateId: string) => {
    if (!templateId) return;
    const template = noteTemplates.value.find((entry) => String(entry.id) === templateId);
    if (!template) return;
    const current = String(formValues.value.default_invoice_notes ?? '');
    const next = current.trim() ? `${current.trim()}\n\n${template.body}` : template.body;
    setFieldValue('default_invoice_notes', next);
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
    default_invoice_notes: z.string().optional(),
    is_active: z.boolean(),
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
                <section>
                    <h3 class="text-sm font-semibold text-slate-900">Details</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Company profile and billing defaults</p>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
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
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                            <AppSelect
                                :model-value="values.is_active ? 'active' : 'inactive'"
                                :options="[{ label: 'Active', value: 'active' }, { label: 'Inactive', value: 'inactive' }]"
                                @update:model-value="setFieldValue('is_active', $event === 'active')"
                            />
                        </div>
                    </div>
                </section>

                <section class="mt-8 border-t border-slate-100 pt-6">
                    <h3 class="text-sm font-semibold text-slate-900">Address</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Billing and postal details for this client</p>

                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <AppInput :model-value="values.address.street" placeholder="Street" @update:model-value="setFieldValue('address.street', $event)" />
                        <AppInput :model-value="values.address.city" placeholder="City" @update:model-value="setFieldValue('address.city', $event)" />
                        <AppInput :model-value="values.address.province" placeholder="Province" @update:model-value="setFieldValue('address.province', $event)" />
                        <AppInput :model-value="values.address.postal_code" placeholder="Postal code" @update:model-value="setFieldValue('address.postal_code', $event)" />
                        <AppInput :model-value="values.address.country" placeholder="Country" @update:model-value="setFieldValue('address.country', $event)" />
                    </div>
                </section>

                <section class="mt-8 border-t border-slate-100 pt-6">
                    <h3 class="text-sm font-semibold text-slate-900">Internal notes</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Private notes about this client — not shown on invoices</p>

                    <div class="mt-4">
                        <MarkdownEditor
                            :model-value="String(values.notes ?? '')"
                            :rows="4"
                            placeholder="Optional internal notes…"
                            aria-label="Client internal notes"
                            @update:model-value="setFieldValue('notes', $event)"
                        />
                    </div>
                </section>

                <section class="mt-8 border-t border-slate-100 pt-6">
                    <h3 class="text-sm font-semibold text-slate-900">Default invoice notes</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Prefills notes on new invoices and estimates for this client. Insert templates, then edit freely.
                    </p>

                    <div class="mt-4 space-y-3">
                        <MarkdownEditor
                            :model-value="String(values.default_invoice_notes ?? '')"
                            :rows="6"
                            placeholder="Payment instructions, banking details…"
                            aria-label="Default invoice notes"
                            @update:model-value="setFieldValue('default_invoice_notes', $event)"
                        />
                        <div v-if="notesTemplateOptions.length">
                            <AppSelect
                                :model-value="''"
                                :options="[{ label: 'Insert note template…', value: '' }, ...notesTemplateOptions]"
                                @update:model-value="insertNoteTemplate(String($event ?? ''))"
                            />
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <AppButton
                                as="a"
                                size="sm"
                                variant="secondary"
                                :href="route('settings.note-templates.index')"
                            >
                                Manage note templates
                            </AppButton>
                        </div>
                    </div>
                </section>

                <FormActions bordered>
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
