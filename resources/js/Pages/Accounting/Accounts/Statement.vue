<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

const props = defineProps<{
    account: {
        id: number;
        code: string;
        name: string;
        type: string;
        normal_balance: 'debit' | 'credit';
    };
    entries: {
        data: Array<{
            id: number;
            date: string | null;
            reference: string | null;
            description: string | null;
            debit: number;
            credit: number;
            running_balance: number;
            is_normal_balance: boolean;
        }>;
        current_page: number;
        last_page: number;
    };
    opening_balance: number;
    closing_balance: number;
    period: {
        from: string;
        to: string;
    };
    totals: {
        debits: number;
        credits: number;
    };
}>();

const period = ref({
    from: props.period.from,
    to: props.period.to,
});

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const applyPeriod = (page = 1) => {
    router.get(route('accounting.accounts.statement', props.account.id), {
        from: period.value.from,
        to: period.value.to,
        page,
    }, { preserveScroll: true, preserveState: true, replace: true });
};

const presetThisMonth = () => {
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth(), 1);
    const last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    period.value.from = first.toISOString().slice(0, 10);
    period.value.to = last.toISOString().slice(0, 10);
    applyPeriod();
};

const presetLastMonth = () => {
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    const last = new Date(now.getFullYear(), now.getMonth(), 0);
    period.value.from = first.toISOString().slice(0, 10);
    period.value.to = last.toISOString().slice(0, 10);
    applyPeriod();
};
</script>

<template>
    <AppLayout
        :title="`${account.code} ${account.name}`"
        :breadcrumbs="[
            { label: 'Accounting' },
            { label: 'Account Statement' },
        ]"
    >
        <PageHeader
            :title="`${account.code} - ${account.name}`"
            :subtitle="`${account.type} account`"
        >
            <template #actions>
                <div class="flex gap-2">
                    <AppButton variant="secondary">Export PDF</AppButton>
                    <AppButton variant="secondary">Export Excel</AppButton>
                </div>
            </template>
        </PageHeader>

        <AppCard class="mt-5">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                    <AppInput v-model="period.from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                    <AppInput v-model="period.to" type="date" />
                </div>
                <AppButton variant="secondary" @click="applyPeriod()">Apply</AppButton>
                <AppButton variant="ghost" @click="presetThisMonth">This month</AppButton>
                <AppButton variant="ghost" @click="presetLastMonth">Last month</AppButton>
            </div>
        </AppCard>

        <AppCard class="mt-5">
            <div class="mb-2 rounded-md bg-slate-100 px-4 py-2 text-sm font-medium text-slate-700">
                Opening balance: {{ formatCents(opening_balance) }}
            </div>

            <AppTable
                :columns="[
                    { key: 'date', label: 'Date' },
                    { key: 'reference', label: 'Reference' },
                    { key: 'description', label: 'Description' },
                    { key: 'debit', label: 'Debit' },
                    { key: 'credit', label: 'Credit' },
                    { key: 'balance', label: 'Balance' },
                ]"
                :page="entries.current_page"
                :last-page="entries.last_page"
                @page-change="applyPeriod"
            >
                <tr v-for="entry in entries.data" :key="entry.id">
                    <td class="px-4 py-3">{{ entry.date || '-' }}</td>
                    <td class="px-4 py-3">{{ entry.reference || '—' }}</td>
                    <td class="px-4 py-3">{{ entry.description || '—' }}</td>
                    <td class="px-4 py-3">{{ entry.debit > 0 ? formatCents(entry.debit) : '—' }}</td>
                    <td class="px-4 py-3">{{ entry.credit > 0 ? formatCents(entry.credit) : '—' }}</td>
                    <td class="px-4 py-3">
                        <span :class="entry.is_normal_balance ? 'text-emerald-700' : 'text-rose-600'">
                            {{ formatCents(entry.running_balance) }}
                        </span>
                    </td>
                </tr>
                <tr v-if="!entries.data.length">
                    <td colspan="6" class="px-4 py-6">
                        <EmptyState title="No entries in period" description="Try a wider date range." />
                    </td>
                </tr>
            </AppTable>

            <div class="mt-3 rounded-md bg-slate-50 px-4 py-2 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="font-medium text-slate-700">Totals</p>
                    <p class="text-slate-600">Debits: <span class="font-semibold">{{ formatCents(totals.debits) }}</span></p>
                    <p class="text-slate-600">Credits: <span class="font-semibold">{{ formatCents(totals.credits) }}</span></p>
                </div>
            </div>

            <div class="mt-2 rounded-md bg-slate-200 px-4 py-2 text-sm font-bold text-slate-800">
                Closing balance: {{ formatCents(closing_balance) }}
            </div>
        </AppCard>
    </AppLayout>
</template>
