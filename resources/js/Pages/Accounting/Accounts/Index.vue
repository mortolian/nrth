<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type AccountRow = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    type: string;
    normal_balance: 'debit' | 'credit';
    is_system: boolean;
    is_active: boolean;
    parent: { code: string; name: string } | null;
    balance_cents: number;
};

type AccountGroup = {
    type: string;
    accounts: AccountRow[];
};

const props = defineProps<{
    groups: AccountGroup[];
}>();

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const typeLabels: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    income: 'Income',
    expense: 'Expenses',
};

const showInactive = ref(false);
const search = ref('');

const filteredGroups = computed(() =>
    props.groups
        .map((g) => ({
            ...g,
            accounts: g.accounts.filter((a) => {
                if (!showInactive.value && !a.is_active) return false;
                if (search.value.trim()) {
                    const q = search.value.toLowerCase();
                    return (
                        a.code.toLowerCase().includes(q) ||
                        a.name.toLowerCase().includes(q) ||
                        (a.description ?? '').toLowerCase().includes(q)
                    );
                }
                return true;
            }),
        }))
        .filter((g) => g.accounts.length > 0),
);

const viewStatement = (id: number) => {
    router.get(route('accounting.accounts.statement', id));
};

const typeBadgeClass: Record<string, string> = {
    asset: 'bg-blue-100 text-blue-700',
    liability: 'bg-rose-100 text-rose-700',
    equity: 'bg-purple-100 text-purple-700',
    income: 'bg-emerald-100 text-emerald-700',
    expense: 'bg-amber-100 text-amber-700',
};
</script>

<template>
    <AppLayout
        title="Chart of Accounts"
        :breadcrumbs="[{ label: 'Accounting' }, { label: 'Chart of Accounts' }]"
    >
        <PageHeader
            title="Chart of Accounts"
            subtitle="All accounts used in your double-entry bookkeeping"
        />

        <AppCard class="mt-5">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-48">
                    <AppInput v-model="search" placeholder="Search by code or name…" />
                </div>
                <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600 select-none">
                    <input v-model="showInactive" type="checkbox" class="rounded border-slate-300" />
                    Show inactive accounts
                </label>
            </div>
        </AppCard>

        <div v-if="filteredGroups.length === 0" class="mt-5">
            <AppCard>
                <EmptyState
                    title="No accounts found"
                    description="Try adjusting your search or enabling inactive accounts."
                />
            </AppCard>
        </div>

        <div v-for="group in filteredGroups" :key="group.type" class="mt-5">
            <div class="mb-2 flex items-center gap-2">
                <span
                    class="rounded-md px-2.5 py-1 text-xs font-semibold uppercase tracking-wide"
                    :class="typeBadgeClass[group.type] ?? 'bg-slate-100 text-slate-600'"
                >
                    {{ typeLabels[group.type] ?? group.type }}
                </span>
                <span class="text-xs text-slate-400">{{ group.accounts.length }} accounts</span>
            </div>

            <AppCard>
                <AppTable
                    :columns="[
                        { key: 'code', label: 'Code' },
                        { key: 'name', label: 'Account Name' },
                        { key: 'parent', label: 'Parent' },
                        { key: 'balance', label: 'Balance' },
                        { key: 'flags', label: '' },
                        { key: 'actions', label: '' },
                    ]"
                >
                    <tr
                        v-for="account in group.accounts"
                        :key="account.id"
                        class="hover:bg-slate-50"
                        :class="{ 'opacity-50': !account.is_active }"
                    >
                        <td class="px-4 py-3 font-mono text-sm text-slate-600">{{ account.code }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-800">{{ account.name }}</div>
                            <div v-if="account.description" class="text-xs text-slate-400 mt-0.5">{{ account.description }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-500">
                            <span v-if="account.parent">{{ account.parent.code }} – {{ account.parent.name }}</span>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="px-4 py-3 text-sm tabular-nums">
                            <span
                                :class="account.balance_cents < 0 ? 'text-rose-600' : 'text-slate-700'"
                            >
                                {{ formatCents(Math.abs(account.balance_cents)) }}
                                <span v-if="account.balance_cents < 0" class="text-xs text-rose-400 ml-1">Cr</span>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-1">
                                <AppBadge v-if="account.is_system" variant="neutral" class="text-xs">System</AppBadge>
                                <AppBadge v-if="!account.is_active" variant="neutral" class="text-xs">Inactive</AppBadge>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <AppButton
                                v-if="account.is_active"
                                size="sm"
                                variant="ghost"
                                @click="viewStatement(account.id)"
                            >
                                View statement
                            </AppButton>
                        </td>
                    </tr>
                </AppTable>
            </AppCard>
        </div>
    </AppLayout>
</template>
