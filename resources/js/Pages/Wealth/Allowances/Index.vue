<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppTable from '@/Components/AppTable.vue';
import type { TableColumn } from '@/Components/AppTable.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type Allowance = {
    id: number;
    label: string;
    owner_name: string;
    asset_name: string | null;
    financial_year_label: string;
    year_starts_on: string;
    year_ends_on: string;
    limit_cents: number;
    contributed_cents: number;
    remaining_cents: number;
    currency: string;
    scheme_key: string | null;
};

const props = defineProps<{
    portfolio: { id: number; base_currency: string };
    allowances: Allowance[];
    assets: Array<{ id: number; name: string; owner_name: string }>;
    can_manage: boolean;
}>();

const currency = computed(() => props.portfolio.base_currency || 'ZAR');
const formatCents = (cents: number, code = currency.value) =>
    useFormatCurrency((Number(cents) || 0) / 100, code);

const showForm = ref(false);
const form = useForm({
    owner_name: '',
    label: '',
    scheme_key: '',
    financial_year_label: '',
    year_starts_on: '',
    year_ends_on: '',
    limit: '',
    asset_id: '' as string | number,
    notes: '',
});

const submit = () => {
    form.transform((data) => ({
        owner_name: data.owner_name,
        label: data.label,
        scheme_key: data.scheme_key || null,
        financial_year_label: data.financial_year_label,
        year_starts_on: data.year_starts_on,
        year_ends_on: data.year_ends_on,
        limit_cents: Math.round(Number(data.limit) * 100),
        asset_id: data.asset_id === '' ? null : Number(data.asset_id),
        notes: data.notes || null,
    })).post(route('wealth.allowances.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
};

const remove = (id: number) => {
    router.delete(route('wealth.allowances.destroy', id), { preserveScroll: true });
};

const columns: TableColumn[] = [
    { key: 'label', label: 'Allowance' },
    { key: 'owner', label: 'Owner' },
    { key: 'asset', label: 'Asset' },
    { key: 'year', label: 'Year' },
    { key: 'limit', label: 'Limit', align: 'right' },
    { key: 'used', label: 'Contributed', align: 'right' },
    { key: 'remaining', label: 'Remaining', align: 'right' },
    { key: 'actions', label: '', align: 'right' },
];
</script>

<template>
    <AppLayout
        title="Contribution allowances"
        :breadcrumbs="[
            { label: 'Wealth', href: route('wealth.index') },
            { label: 'Allowances' },
        ]"
    >
        <PageHeader
            title="Contribution allowances"
            subtitle="Track annual contribution limits (e.g. tax-free savings). Remaining is derived from contribution transactions."
        >
            <template #actions>
                <div class="flex gap-2">
                    <Link :href="route('wealth.index')">
                        <AppButton variant="secondary" size="sm">Back</AppButton>
                    </Link>
                    <AppButton v-if="can_manage" variant="primary" size="sm" @click="showForm = !showForm">
                        {{ showForm ? 'Close' : 'Add allowance' }}
                    </AppButton>
                </div>
            </template>
        </PageHeader>

        <AppCard v-if="showForm && can_manage" class="mt-6">
            <h3 class="mb-4 text-base font-semibold text-slate-900">New allowance</h3>
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <div>
                    <InputLabel value="Label" />
                    <TextInput v-model="form.label" class="mt-1 w-full" required placeholder="TFSA 2026" />
                </div>
                <div>
                    <InputLabel value="Owner" />
                    <TextInput v-model="form.owner_name" class="mt-1 w-full" required />
                </div>
                <div>
                    <InputLabel value="Asset (optional)" />
                    <select v-model="form.asset_id" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                        <option value="">All assets for owner</option>
                        <option v-for="a in assets" :key="a.id" :value="a.id">{{ a.name }} ({{ a.owner_name }})</option>
                    </select>
                </div>
                <div>
                    <InputLabel value="Scheme key (optional)" />
                    <TextInput v-model="form.scheme_key" class="mt-1 w-full" placeholder="za_tfsa" />
                </div>
                <div>
                    <InputLabel value="Financial year label" />
                    <TextInput v-model="form.financial_year_label" class="mt-1 w-full" required placeholder="2026" />
                </div>
                <div>
                    <InputLabel :value="`Annual limit (${portfolio.base_currency})`" />
                    <TextInput v-model="form.limit" type="number" step="0.01" min="0" class="mt-1 w-full" required />
                </div>
                <div>
                    <InputLabel value="Year starts" />
                    <TextInput v-model="form.year_starts_on" type="date" class="mt-1 w-full" required />
                </div>
                <div>
                    <InputLabel value="Year ends" />
                    <TextInput v-model="form.year_ends_on" type="date" class="mt-1 w-full" required />
                </div>
                <div class="sm:col-span-2">
                    <FormActions>
                        <AppButton type="submit" variant="primary" :disabled="form.processing">Save</AppButton>
                        <AppButton type="button" variant="secondary" @click="showForm = false">Cancel</AppButton>
                    </FormActions>
                </div>
            </form>
        </AppCard>

        <AppCard class="mt-6 overflow-hidden p-0">
            <AppTable v-if="allowances.length" :columns="columns" :show-pagination="false" dense table-class="text-sm" embedded>
                <tr v-for="row in allowances" :key="row.id">
                    <td class="whitespace-nowrap px-3 py-2 font-medium">{{ row.label }}</td>
                    <td class="whitespace-nowrap px-3 py-2">{{ row.owner_name }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ row.asset_name || 'Owner-level' }}</td>
                    <td class="whitespace-nowrap px-3 py-2">{{ row.financial_year_label }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatCents(row.limit_cents, row.currency) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatCents(row.contributed_cents, row.currency) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums font-medium">{{ formatCents(row.remaining_cents, row.currency) }}</td>
                    <td class="px-3 py-2 text-right">
                        <button
                            v-if="can_manage"
                            type="button"
                            class="text-xs text-red-600 hover:underline"
                            @click="remove(row.id)"
                        >
                            Remove
                        </button>
                    </td>
                </tr>
            </AppTable>
            <p v-else class="px-5 py-6 text-sm text-slate-500">No contribution allowances yet.</p>
        </AppCard>
    </AppLayout>
</template>
