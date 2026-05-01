<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppPhoneInput from '@/Components/AppPhoneInput.vue';

const props = defineProps<{
    isEditing: boolean;
    return_to?: string | null;
    supplier: null | {
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
        notes: string | null;
        is_active: boolean;
    };
}>();

const { values, setFieldValue } = useForm({
    initialValues: {
        name: props.supplier?.name ?? '',
        contact_name: props.supplier?.contact_name ?? '',
        email: props.supplier?.email ?? '',
        phone: props.supplier?.phone ?? '',
        vat_number: props.supplier?.vat_number ?? '',
        registration_number: props.supplier?.registration_number ?? '',
        address: {
            street: props.supplier?.address?.street ?? '',
            city: props.supplier?.address?.city ?? '',
            province: props.supplier?.address?.province ?? '',
            postal_code: props.supplier?.address?.postal_code ?? '',
            country: props.supplier?.address?.country ?? 'South Africa',
        },
        notes: props.supplier?.notes ?? '',
        is_active: props.supplier?.is_active ?? true,
    },
});

const formValues = computed<Record<string, any>>(() => ((values as any)?.value ?? values) as Record<string, any>);

const schema = z.object({
    name: z.string().min(1, 'Supplier name is required'),
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
    notes: z.string().optional(),
    is_active: z.boolean(),
});

const submit = () => {
    const result = schema.safeParse(formValues.value);
    if (!result.success) return;

    if (props.isEditing && props.supplier) {
        router.put(route('suppliers.update', props.supplier.id), result.data);
        return;
    }
    const payload = props.return_to ? { ...result.data, return: props.return_to } : result.data;
    router.post(route('suppliers.store'), payload);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit Supplier' : 'New Supplier'"
        :breadcrumbs="[
            { label: 'Money Out' },
            { label: 'Suppliers', href: route('suppliers.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader :title="isEditing ? 'Edit Supplier' : 'Create Supplier'" subtitle="Vendors you pay on expenses" />

        <AppCard class="mt-5">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Supplier name</label>
                    <AppInput :model-value="values.name" @update:model-value="setFieldValue('name', $event)" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Contact name</label>
                    <AppInput :model-value="values.contact_name" @update:model-value="setFieldValue('contact_name', $event)" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Email</label>
                    <AppInput :model-value="values.email" type="email" @update:model-value="setFieldValue('email', $event)" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Phone</label>
                    <AppPhoneInput :model-value="values.phone" @update:model-value="setFieldValue('phone', $event ?? '')" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">VAT number</label>
                    <AppInput :model-value="values.vat_number" placeholder="4XXXXXXXXX" @update:model-value="setFieldValue('vat_number', $event)" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Registration number</label>
                    <AppInput :model-value="values.registration_number" @update:model-value="setFieldValue('registration_number', $event)" />
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
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                    <textarea
                        :value="values.notes"
                        class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        @input="setFieldValue('notes', ($event.target as HTMLTextAreaElement).value)"
                    />
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
            <div class="mt-5 flex justify-end gap-2">
                <AppButton variant="ghost" @click="router.visit(route('suppliers.index'))">Cancel</AppButton>
                <AppButton variant="primary" @click="submit">{{ isEditing ? 'Update Supplier' : 'Create Supplier' }}</AppButton>
            </div>
        </AppCard>
    </AppLayout>
</template>
