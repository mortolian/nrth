<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormActions from '@/Components/FormActions.vue';
import FormValidationBanner from '@/Components/FormValidationBanner.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useToast } from '@/Composables/useToast';

const props = defineProps<{
    isEditing: boolean;
    budget: null | {
        id: number;
        name: string;
        period_type: 'monthly' | 'quarterly' | 'annual' | 'custom';
        start_date: string | null;
        end_date: string | null;
        currency: string;
        is_active: boolean;
    };
}>();

const page = usePage();
const toast = useToast();
const saving = ref(false);
const { fieldErrors, setFromZod, setFromServer, clear, clearField, messages: clientErrorMessages } = useFieldErrors();

const currencyOptions = computed(
    () => (page.props.currencyOptions as Array<{ value: string; label: string }>) ?? [],
);

const today = new Date().toISOString().slice(0, 10);

const form = reactive({
    name: props.budget?.name ?? '',
    period_type: (props.budget?.period_type ?? 'monthly') as 'monthly' | 'quarterly' | 'annual' | 'custom',
    start_date: props.budget?.start_date ?? today,
    end_date: props.budget?.end_date ?? today,
    currency: props.budget?.currency ?? 'ZAR',
    set_active: props.budget?.is_active ?? true,
});

const applyPeriodType = () => {
    const start = new Date(`${form.start_date}T12:00:00`);
    if (Number.isNaN(start.getTime())) return;

    if (form.period_type === 'monthly') {
        const end = new Date(start.getFullYear(), start.getMonth() + 1, 0);
        form.end_date = end.toISOString().slice(0, 10);
    } else if (form.period_type === 'quarterly') {
        const qStartMonth = Math.floor(start.getMonth() / 3) * 3;
        const qStart = new Date(start.getFullYear(), qStartMonth, 1);
        form.start_date = qStart.toISOString().slice(0, 10);
        const end = new Date(qStart.getFullYear(), qStart.getMonth() + 3, 0);
        form.end_date = end.toISOString().slice(0, 10);
    } else if (form.period_type === 'annual') {
        const yStart = new Date(start.getFullYear(), 0, 1);
        form.start_date = yStart.toISOString().slice(0, 10);
        const end = new Date(start.getFullYear(), 11, 31);
        form.end_date = end.toISOString().slice(0, 10);
    }
};

if (!props.isEditing) {
    applyPeriodType();
}

watch(
    () => form.period_type,
    () => {
        if (form.period_type !== 'custom') {
            applyPeriodType();
        }
    },
);

const schema = z.object({
    name: z.string().trim().min(1, 'Budget name is required'),
    period_type: z.enum(['monthly', 'quarterly', 'annual', 'custom']),
    start_date: z.string().min(1, 'Start date is required'),
    end_date: z.string().min(1, 'End date is required'),
    currency: z.string().length(3, 'Currency is required'),
    set_active: z.boolean(),
});

const submit = () => {
    if (saving.value) return;

    const parsed = schema.safeParse({ ...form });
    if (!parsed.success) {
        setFromZod(parsed.error);
        return;
    }

    if (parsed.data.end_date < parsed.data.start_date) {
        setFromServer({ end_date: 'End date must be on or after the start date.' });
        return;
    }

    clear();

    const payload = {
        name: parsed.data.name,
        period_type: parsed.data.period_type,
        start_date: parsed.data.start_date,
        end_date: parsed.data.end_date,
        currency: parsed.data.currency,
        set_active: parsed.data.set_active,
    };

    const visitOptions = {
        onStart: () => {
            saving.value = true;
        },
        onSuccess: () => {
            toast.success(props.isEditing ? 'Budget details updated.' : 'Budget created.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
            if (!Object.keys(errors).length) {
                toast.error('Could not save this budget.');
            }
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (props.isEditing && props.budget) {
        router.put(route('budgeting.update', props.budget.id), payload, visitOptions);
        return;
    }

    router.post(route('budgeting.store'), payload, visitOptions);
};

const cancel = () => {
    if (props.isEditing && props.budget) {
        router.visit(route('budgeting.show', props.budget.id));
        return;
    }
    router.visit(route('budgeting.index'));
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit budget details' : 'Create budget'"
        :breadcrumbs="[
            { label: 'Planning' },
            { label: 'Budgets', href: route('budgeting.index') },
            ...(isEditing && budget
                ? [{ label: budget.name, href: route('budgeting.show', budget.id) }, { label: 'Edit details' }]
                : [{ label: 'Create' }]),
        ]"
    >
        <PageHeader
            :title="isEditing ? 'Edit budget details' : 'Create budget'"
            :subtitle="
                isEditing
                    ? 'Update the budget period and currency. Categories and line items are managed on the budget page.'
                    : 'Start with the basics. You will add categories and known expenses on the next screen.'
            "
        />

        <FormValidationBanner class="mt-5" :errors="clientErrorMessages" />

        <AppCard class="mt-5">
            <form @submit.prevent="submit">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">
                            Budget name <span class="text-rose-600">*</span>
                        </label>
                        <AppInput
                            v-model="form.name"
                            placeholder="2026 Operating budget"
                            @update:model-value="clearField('name')"
                        />
                        <p v-if="fieldErrors.name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Period type</label>
                        <AppSelect
                            :model-value="form.period_type"
                            :options="[
                                { label: 'Monthly', value: 'monthly' },
                                { label: 'Quarterly', value: 'quarterly' },
                                { label: 'Annual', value: 'annual' },
                                { label: 'Custom', value: 'custom' },
                            ]"
                            @update:model-value="
                                form.period_type = $event as 'monthly' | 'quarterly' | 'annual' | 'custom';
                                clearField('period_type');
                                if (form.period_type !== 'custom') applyPeriodType();
                            "
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Budget currency</label>
                        <AppSelect
                            :model-value="form.currency"
                            :options="currencyOptions"
                            @update:model-value="
                                form.currency = $event;
                                clearField('currency');
                            "
                        />
                        <p v-if="fieldErrors.currency" class="mt-1 text-xs text-rose-600">{{ fieldErrors.currency }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Start date</label>
                        <AppInput
                            v-model="form.start_date"
                            type="date"
                            @change="
                                clearField('start_date');
                                if (form.period_type !== 'custom') applyPeriodType();
                            "
                        />
                        <p v-if="fieldErrors.start_date" class="mt-1 text-xs text-rose-600">{{ fieldErrors.start_date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">End date</label>
                        <AppInput
                            v-model="form.end_date"
                            type="date"
                            :disabled="form.period_type !== 'custom'"
                            @update:model-value="clearField('end_date')"
                        />
                        <p v-if="fieldErrors.end_date" class="mt-1 text-xs text-rose-600">{{ fieldErrors.end_date }}</p>
                    </div>
                    <div class="md:col-span-2 flex items-center gap-2 pt-2">
                        <input
                            id="budget-set-active"
                            v-model="form.set_active"
                            type="checkbox"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        <label for="budget-set-active" class="text-sm text-slate-700">
                            Set as active budget (overview &amp; dashboard)
                        </label>
                    </div>
                </div>

                <FormActions bordered>
                    <AppButton type="submit" variant="primary" :disabled="saving">
                        {{ isEditing ? 'Save details' : 'Create budget' }}
                    </AppButton>
                    <AppButton type="button" variant="secondary" :disabled="saving" @click="cancel">
                        Cancel
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </AppLayout>
</template>
