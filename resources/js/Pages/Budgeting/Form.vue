<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

type Line = {
    account_id: number;
    account_name: string;
    monthly_amount_cents: number;
    annual_total_cents: number;
};

const props = defineProps<{
    isEditing: boolean;
    budget: null | {
        id: number;
        name: string;
        period_type: 'monthly' | 'quarterly' | 'annual' | 'custom';
        start_date: string | null;
        end_date: string | null;
        currency: string;
        lines: Line[];
    };
    expense_accounts: Array<{ id: number; name: string }>;
    import_lines: Array<{ account_id: number; monthly_amount_cents: number }>;
}>();

const today = new Date().toISOString().slice(0, 10);
const { values } = useForm({
    initialValues: {
        name: props.budget?.name ?? '',
        period_type: props.budget?.period_type ?? 'monthly',
        start_date: props.budget?.start_date ?? today,
        end_date: props.budget?.end_date ?? today,
        currency: props.budget?.currency ?? 'ZAR',
        set_active: true,
        lines: (props.budget?.lines?.length
            ? props.budget.lines
            : props.expense_accounts.map((account) => ({
                account_id: account.id,
                account_name: account.name,
                monthly_amount_cents: 0,
                annual_total_cents: 0,
            }))) as Line[],
    },
});

const schema = z.object({
    name: z.string().min(1),
    period_type: z.enum(['monthly', 'quarterly', 'annual', 'custom']),
    start_date: z.string().min(1),
    end_date: z.string().min(1),
    currency: z.literal('ZAR'),
    set_active: z.boolean().optional(),
    lines: z.array(z.object({
        account_id: z.number().int().positive(),
        monthly_amount_cents: z.number().int().min(0),
    })).min(1),
});

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const totalMonthly = computed(() => values.value.lines.reduce((sum, line) => sum + Number(line.monthly_amount_cents || 0), 0));
const totalAnnual = computed(() => values.value.lines.reduce((sum, line) => sum + Number(line.annual_total_cents || 0), 0));

const recalcLine = (line: Line) => {
    const monthly = Number(line.monthly_amount_cents || 0);
    line.annual_total_cents = monthly * 12;
};

const applyPeriodType = () => {
    const now = new Date(values.value.start_date || today);
    if (values.value.period_type === 'monthly') {
        const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        values.value.end_date = end.toISOString().slice(0, 10);
    } else if (values.value.period_type === 'quarterly') {
        const month = now.getMonth();
        const quarterStart = month - (month % 3);
        const start = new Date(now.getFullYear(), quarterStart, 1);
        const end = new Date(now.getFullYear(), quarterStart + 3, 0);
        values.value.start_date = start.toISOString().slice(0, 10);
        values.value.end_date = end.toISOString().slice(0, 10);
    } else if (values.value.period_type === 'annual') {
        const start = new Date(now.getFullYear(), 0, 1);
        const end = new Date(now.getFullYear(), 11, 31);
        values.value.start_date = start.toISOString().slice(0, 10);
        values.value.end_date = end.toISOString().slice(0, 10);
    }
};

const importFromLastPeriod = () => {
    const byAccount = new Map(props.import_lines.map((line) => [line.account_id, line.monthly_amount_cents]));
    values.value.lines = values.value.lines.map((line) => {
        const monthly = Number(byAccount.get(line.account_id) ?? 0);
        return {
            ...line,
            monthly_amount_cents: monthly,
            annual_total_cents: monthly * 12,
        };
    });
};

const submit = () => {
    const parsed = schema.safeParse({
        ...values.value,
        lines: values.value.lines.map((line) => ({
            account_id: Number(line.account_id),
            monthly_amount_cents: Number(line.monthly_amount_cents),
        })),
    });
    if (!parsed.success) return;

    const payload = parsed.data;
    if (props.isEditing && props.budget) {
        router.put(route('budgeting.update', props.budget.id), payload);
        return;
    }
    router.post(route('budgeting.store'), payload);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit Budget' : 'Create Budget'"
        :breadcrumbs="[
            { label: 'Planning' },
            { label: 'Budgeting', href: route('budgeting.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader :title="isEditing ? 'Edit Budget' : 'Create Budget'" subtitle="Plan allocations per expense category" />

        <AppCard class="mt-5">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Budget name</label>
                    <AppInput v-model="values.name" placeholder="2025 Annual Budget" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Period type</label>
                    <AppSelect
                        :model-value="values.period_type"
                        :options="[
                            { label: 'Monthly', value: 'monthly' },
                            { label: 'Quarterly', value: 'quarterly' },
                            { label: 'Annual', value: 'annual' },
                            { label: 'Custom', value: 'custom' },
                        ]"
                        @update:model-value="values.period_type = $event as 'monthly' | 'quarterly' | 'annual' | 'custom'; applyPeriodType()"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Start date</label>
                    <AppInput v-model="values.start_date" type="date" @change="applyPeriodType" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">End date</label>
                    <AppInput v-model="values.end_date" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Currency</label>
                    <AppSelect
                        :model-value="values.currency"
                        :options="[{ label: 'ZAR', value: 'ZAR' }]"
                        @update:model-value="values.currency = $event"
                    />
                </div>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-slate-900">Budget lines</h3>
                <AppButton variant="secondary" @click="importFromLastPeriod">Import from last period</AppButton>
            </div>
            <AppTable
                :columns="[
                    { key: 'category', label: 'Category' },
                    { key: 'monthly', label: 'Monthly Amount' },
                    { key: 'annual', label: 'Annual Total' },
                ]"
                :page="1"
                :last-page="1"
            >
                <tr v-for="line in values.lines" :key="line.account_id">
                    <td class="px-4 py-3">{{ line.account_name }}</td>
                    <td class="px-4 py-3">
                        <AppInput v-model="line.monthly_amount_cents" type="number" @input="recalcLine(line)" />
                    </td>
                    <td class="px-4 py-3 font-medium">{{ formatCents(line.annual_total_cents) }}</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-semibold text-slate-900">Running total</td>
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ formatCents(totalMonthly) }}</td>
                    <td class="px-4 py-3 font-semibold text-slate-900">{{ formatCents(totalAnnual) }}</td>
                </tr>
            </AppTable>
        </AppCard>

        <div class="mt-5 flex justify-end gap-2">
            <AppButton variant="ghost" @click="router.visit(route('budgeting.index'))">Cancel</AppButton>
            <AppButton variant="primary" @click="submit">Save Budget</AppButton>
        </div>
    </AppLayout>
</template>
