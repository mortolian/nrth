<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useToast } from '@/Composables/useToast';

const props = defineProps<{
    isEditing: boolean;
    contract: null | {
        id: number;
        client_id: number;
        title: string;
        status: 'draft' | 'active' | 'expired' | 'terminated';
        billing_type: 'fixed' | 'time_materials' | 'retainer';
        start_date: string | null;
        end_date: string | null;
        contract_value_cents: number;
        hourly_rate_cents: number;
        monthly_amount_cents: number;
        payment_terms: string | null;
        scope_of_work: string | null;
        next_invoice_due_date: string | null;
        signed_contract_url: string | null;
    };
    clients: Array<{ id: number; name: string }>;
}>();

const { fieldErrors, setFromZod, setFromServer, clear, clearField } = useFieldErrors();

const { values } = useForm({
    initialValues: {
        client_id: props.contract?.client_id ?? (props.clients[0]?.id ?? 0),
        title: props.contract?.title ?? '',
        status: props.contract?.status ?? 'draft',
        billing_type: props.contract?.billing_type ?? 'fixed',
        start_date: props.contract?.start_date ?? new Date().toISOString().slice(0, 10),
        end_date: props.contract?.end_date ?? '',
        contract_value: ((props.contract?.contract_value_cents ?? 0) / 100).toFixed(2),
        hourly_rate: ((props.contract?.hourly_rate_cents ?? 0) / 100).toFixed(2),
        monthly_amount: ((props.contract?.monthly_amount_cents ?? 0) / 100).toFixed(2),
        payment_terms: props.contract?.payment_terms ?? '',
        scope_of_work: props.contract?.scope_of_work ?? '',
        signed_contract: null as File | null,
    },
});

const schema = z.object({
    client_id: z.coerce.number().int().positive('Select a client'),
    title: z.string().trim().min(1, 'Title is required'),
    status: z.enum(['draft', 'active', 'expired', 'terminated']),
    billing_type: z.enum(['fixed', 'time_materials', 'retainer']),
    start_date: z.string().min(1, 'Start date is required'),
    end_date: z.string().optional(),
    contract_value: z.coerce.number().min(0, 'Value cannot be negative'),
    hourly_rate: z.coerce.number().min(0, 'Rate cannot be negative'),
    monthly_amount: z.coerce.number().min(0, 'Amount cannot be negative'),
    payment_terms: z.string().optional(),
    scope_of_work: z.string().optional(),
});

const valueLabel = computed(() => {
    if (values.value.billing_type === 'time_materials') return 'Hourly rate';
    if (values.value.billing_type === 'retainer') return 'Monthly amount';
    return 'Contract value';
});

const selectedAmount = computed(() => {
    if (values.value.billing_type === 'time_materials') return Number(values.value.hourly_rate || 0);
    if (values.value.billing_type === 'retainer') return Number(values.value.monthly_amount || 0);
    return Number(values.value.contract_value || 0);
});

const onFile = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    values.value.signed_contract = file;
};

const setValue = <K extends keyof typeof values.value>(key: K, value: (typeof values.value)[K]) => {
    values.value[key] = value;
    clearField(String(key));
};

const toast = useToast();
const saving = ref(false);

const submit = () => {
    if (saving.value) return;

    const parsed = schema.safeParse(values.value);
    if (!parsed.success) {
        setFromZod(parsed.error);
        return;
    }

    clear();

    const form = new FormData();
    form.set('client_id', String(parsed.data.client_id));
    form.set('title', parsed.data.title);
    form.set('status', parsed.data.status);
    form.set('billing_type', parsed.data.billing_type);
    form.set('start_date', parsed.data.start_date);
    if (parsed.data.end_date) form.set('end_date', parsed.data.end_date);
    form.set('contract_value_cents', String(Math.round(parsed.data.contract_value * 100)));
    form.set('hourly_rate_cents', String(Math.round(parsed.data.hourly_rate * 100)));
    form.set('monthly_amount_cents', String(Math.round(parsed.data.monthly_amount * 100)));
    form.set('payment_terms', parsed.data.payment_terms ?? '');
    form.set('scope_of_work', parsed.data.scope_of_work ?? '');
    if (values.value.signed_contract) form.set('signed_contract', values.value.signed_contract);

    const visitOptions = {
        onStart: () => {
            saving.value = true;
        },
        onSuccess: () => {
            toast.success(props.isEditing ? 'Contract saved.' : 'Contract created.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
            if (!Object.keys(errors).length) {
                toast.error('Could not save this contract.');
            }
        },
        onFinish: () => {
            saving.value = false;
        },
        forceFormData: true,
    };

    if (props.isEditing && props.contract) {
        form.set('_method', 'PUT');
        router.post(route('contracting.contracts.update', props.contract.id), form, visitOptions);
        return;
    }
    router.post(route('contracting.contracts.store'), form, visitOptions);
};

const generateRetainerInvoice = () => {
    if (!props.isEditing || !props.contract || props.contract.billing_type !== 'retainer') return;
    router.post(route('contracting.contracts.generate-invoice', props.contract.id));
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit Contract' : 'New Contract'"
        :breadcrumbs="[
            { label: 'Contracting' },
            { label: 'Contracts', href: route('contracting.contracts.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader :title="isEditing ? 'Edit Contract' : 'Create Contract'" subtitle="Define billing terms and upload signed agreement" />

        <AppCard class="mt-5">
            <form @submit.prevent="submit">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Client</label>
                        <AppSelect
                            :model-value="String(values.client_id)"
                            :options="clients.map((client) => ({ label: client.name, value: String(client.id) }))"
                            @update:model-value="setValue('client_id', Number($event))"
                        />
                        <p v-if="fieldErrors.client_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.client_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Contract title</label>
                        <AppInput :model-value="values.title" @update:model-value="setValue('title', $event)" />
                        <p v-if="fieldErrors.title" class="mt-1 text-xs text-rose-600">{{ fieldErrors.title }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                        <AppSelect
                            :model-value="values.status"
                            :options="[
                                { label: 'Draft', value: 'draft' },
                                { label: 'Active', value: 'active' },
                                { label: 'Expired', value: 'expired' },
                                { label: 'Terminated', value: 'terminated' },
                            ]"
                            @update:model-value="setValue('status', $event as 'draft' | 'active' | 'expired' | 'terminated')"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Contract type</label>
                        <AppSelect
                            :model-value="values.billing_type"
                            :options="[
                                { label: 'Fixed Price', value: 'fixed' },
                                { label: 'Time & Materials', value: 'time_materials' },
                                { label: 'Monthly Retainer', value: 'retainer' },
                            ]"
                            @update:model-value="setValue('billing_type', $event as 'fixed' | 'time_materials' | 'retainer')"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Start date</label>
                        <AppInput :model-value="values.start_date" type="date" @update:model-value="setValue('start_date', $event)" />
                        <p v-if="fieldErrors.start_date" class="mt-1 text-xs text-rose-600">{{ fieldErrors.start_date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">End date</label>
                        <AppInput :model-value="values.end_date" type="date" @update:model-value="setValue('end_date', $event)" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">{{ valueLabel }}</label>
                        <AppInput
                            v-if="values.billing_type === 'fixed'"
                            :model-value="values.contract_value"
                            type="text"
                            inputmode="decimal"
                            step="0.01"
                            pattern="^\\d*(\\.\\d{0,2})?$"
                            @update:model-value="setValue('contract_value', $event)"
                        />
                        <AppInput
                            v-else-if="values.billing_type === 'time_materials'"
                            :model-value="values.hourly_rate"
                            type="text"
                            inputmode="decimal"
                            step="0.01"
                            pattern="^\\d*(\\.\\d{0,2})?$"
                            @update:model-value="setValue('hourly_rate', $event)"
                        />
                        <AppInput
                            v-else
                            :model-value="values.monthly_amount"
                            type="text"
                            inputmode="decimal"
                            step="0.01"
                            pattern="^\\d*(\\.\\d{0,2})?$"
                            @update:model-value="setValue('monthly_amount', $event)"
                        />
                        <p
                            v-if="fieldErrors.contract_value || fieldErrors.hourly_rate || fieldErrors.monthly_amount"
                            class="mt-1 text-xs text-rose-600"
                        >
                            {{ fieldErrors.contract_value || fieldErrors.hourly_rate || fieldErrors.monthly_amount }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">{{ useFormatCurrency(selectedAmount, 'ZAR') }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Payment terms</label>
                        <AppInput
                            :model-value="values.payment_terms"
                            placeholder="e.g. 30 days from invoice"
                            @update:model-value="setValue('payment_terms', $event)"
                        />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Scope of work</label>
                        <textarea
                            :value="values.scope_of_work"
                            class="min-h-28 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                            @input="setValue('scope_of_work', ($event.target as HTMLTextAreaElement).value)"
                        />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Signed contract document (PDF)</label>
                        <input type="file" accept="application/pdf" class="block w-full rounded-md border border-slate-300 p-2 text-sm" @change="onFile">
                        <a
                            v-if="contract?.signed_contract_url"
                            :href="contract.signed_contract_url"
                            target="_blank"
                            class="mt-2 inline-block text-sm text-brand-700 hover:underline"
                        >
                            View current signed contract
                        </a>
                        <p v-if="fieldErrors.signed_contract" class="mt-1 text-xs text-rose-600">{{ fieldErrors.signed_contract }}</p>
                    </div>
                </div>

                <div v-if="values.billing_type === 'retainer'" class="mt-5 rounded-md border border-brand-200 bg-brand-50 p-3 text-sm">
                    <p class="font-medium text-brand-900">Retainer invoicing</p>
                    <p class="mt-1 text-brand-800">Next invoice due date: {{ contract?.next_invoice_due_date || 'Will be set on save' }}</p>
                    <AppButton
                        v-if="isEditing"
                        class="mt-2"
                        size="sm"
                        variant="secondary"
                        type="button"
                        @click="generateRetainerInvoice"
                    >
                        Generate Invoice
                    </AppButton>
                </div>

                <FormActions>
                    <AppButton type="submit" variant="primary" :loading="saving">
                        {{ saving ? 'Saving…' : 'Save Contract' }}
                    </AppButton>
                    <AppButton type="button" variant="secondary" @click="router.visit(route('contracting.contracts.index'))">
                        Cancel
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </AppLayout>
</template>
