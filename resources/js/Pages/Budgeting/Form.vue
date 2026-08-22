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
        has_period: boolean;
        period_type: 'monthly' | 'quarterly' | 'annual' | 'custom' | null;
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
    has_period: props.budget?.has_period ?? false,
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

watch(
    () => form.has_period,
    (enabled) => {
        if (enabled && form.period_type !== 'custom') {
            applyPeriodType();
        }
    },
);

watch(
    () => form.period_type,
    () => {
        if (form.has_period && form.period_type !== 'custom') {
            applyPeriodType();
        }
    },
);

const schema = z
    .object({
        name: z.string().trim().min(1, 'Budget name is required'),
        has_period: z.boolean(),
        period_type: z.enum(['monthly', 'quarterly', 'annual', 'custom']),
        start_date: z.string(),
        end_date: z.string(),
        currency: z.string().length(3, 'Currency is required'),
        set_active: z.boolean(),
    })
    .superRefine((data, ctx) => {
        if (!data.has_period) {
            return;
        }
        if (!data.start_date) {
            ctx.addIssue({ code: 'custom', message: 'Start date is required', path: ['start_date'] });
        }
        if (!data.end_date) {
            ctx.addIssue({ code: 'custom', message: 'End date is required', path: ['end_date'] });
        }
        if (data.start_date && data.end_date && data.end_date < data.start_date) {
            ctx.addIssue({
                code: 'custom',
                message: 'End date must be on or after the start date.',
                path: ['end_date'],
            });
        }
    });

const submit = () => {
    if (saving.value) return;

    const parsed = schema.safeParse({ ...form });
    if (!parsed.success) {
        setFromZod(parsed.error);
        return;
    }

    clear();

    const payload = {
        name: parsed.data.name,
        has_period: parsed.data.has_period,
        period_type: parsed.data.has_period ? parsed.data.period_type : null,
        start_date: parsed.data.has_period ? parsed.data.start_date : null,
        end_date: parsed.data.has_period ? parsed.data.end_date : null,
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
                    ? 'Update the budget name, currency, and optional period. Categories and line items are managed on the budget page.'
                    : 'Create an ongoing plan, or enable a fixed period if this budget should only cover a date range.'
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
                            placeholder="Operating budget"
                            @update:model-value="clearField('name')"
                        />
                        <p v-if="fieldErrors.name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name }}</p>
                    </div>
                    <div class="md:col-span-2">
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

                    <div class="md:col-span-2 flex items-start gap-2 rounded-md border border-slate-200 bg-slate-50/80 px-3 py-3">
                        <input
                            id="budget-has-period"
                            v-model="form.has_period"
                            type="checkbox"
                            class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        >
                        <div>
                            <label for="budget-has-period" class="text-sm font-medium text-slate-800">
                                Limit this budget to a period
                            </label>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Leave unchecked for an ongoing plan. Enable to set a period type and start/end dates.
                            </p>
                        </div>
                    </div>

                    <template v-if="form.has_period">
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
                            <p v-if="fieldErrors.period_type" class="mt-1 text-xs text-rose-600">{{ fieldErrors.period_type }}</p>
                        </div>
                        <div class="hidden md:block" />
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
                    </template>

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
