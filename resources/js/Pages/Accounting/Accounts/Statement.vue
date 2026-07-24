<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type StatementEntry = {
    id: number;
    date: string | null;
    reference: string | null;
    description: string | null;
    debit: number;
    credit: number;
    running_balance: number;
    is_normal_balance: boolean;
};

const props = defineProps<{
    account: {
        id: number;
        code: string;
        name: string;
        type: string;
        normal_balance: 'debit' | 'credit';
    };
    entries: {
        data: StatementEntry[];
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

const selected = ref<number[]>([]);

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

const toggleSelected = (id: number, checked: boolean) => {
    if (checked) {
        if (!selected.value.includes(id)) selected.value.push(id);
        return;
    }
    selected.value = selected.value.filter((item) => item !== id);
};

const pageIds = computed(() => props.entries.data.map((entry) => entry.id));

const allPageSelected = computed(
    () => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)),
);

const somePageSelected = computed(
    () => pageIds.value.some((id) => selected.value.includes(id)) && !allPageSelected.value,
);

const selectAllCheckbox = ref<HTMLInputElement | null>(null);

watch([allPageSelected, somePageSelected], async () => {
    await nextTick();
    if (selectAllCheckbox.value) {
        selectAllCheckbox.value.indeterminate = somePageSelected.value;
    }
}, { immediate: true });

const toggleSelectAllPage = (checked: boolean) => {
    if (checked) {
        selected.value = [...new Set([...selected.value, ...pageIds.value])];
        return;
    }
    const onPage = new Set(pageIds.value);
    selected.value = selected.value.filter((id) => !onPage.has(id));
};

const exportSelectedCsv = () => {
    if (selected.value.length === 0) {
        return;
    }

    const params = new URLSearchParams();
    selected.value.forEach((id) => {
        params.append('ids[]', String(id));
    });
    window.location.assign(
        `${route('accounting.accounts.statement.export', props.account.id)}?${params.toString()}`,
    );
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
        />

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
            <div class="mb-3 flex items-center justify-between gap-3">
                <h3 class="text-lg font-semibold text-slate-900">Statement entries</h3>
                <AppButton
                    variant="secondary"
                    size="sm"
                    :disabled="selected.length === 0"
                    @click="exportSelectedCsv"
                >
                    Export CSV
                </AppButton>
            </div>

            <div class="mb-2 rounded-md bg-slate-100 px-4 py-2 text-sm font-medium text-slate-700">
                Opening balance: {{ formatCents(opening_balance) }}
            </div>

            <AppTable
                table-class="text-sm"
                :columns="[
                    { key: 'select', label: '', widthClass: 'w-10' },
                    { key: 'date', label: 'Date' },
                    { key: 'reference', label: 'Reference' },
                    { key: 'description', label: 'Description' },
                    { key: 'debit', label: 'Debit', widthClass: 'whitespace-nowrap text-right tabular-nums' },
                    { key: 'credit', label: 'Credit', widthClass: 'whitespace-nowrap text-right tabular-nums' },
                    { key: 'balance', label: 'Balance', widthClass: 'whitespace-nowrap text-right tabular-nums' },
                ]"
                :page="entries.current_page"
                :last-page="entries.last_page"
                @page-change="applyPeriod"
            >
                <template #header-select>
                    <input
                        ref="selectAllCheckbox"
                        type="checkbox"
                        class="h-4 w-4 rounded border-slate-300"
                        :checked="allPageSelected"
                        :disabled="entries.data.length === 0"
                        :aria-label="allPageSelected ? 'Deselect all on this page' : 'Select all on this page'"
                        @change="toggleSelectAllPage(($event.target as HTMLInputElement).checked)"
                    >
                </template>
                <tr v-for="entry in entries.data" :key="entry.id">
                    <td class="px-3 py-2" @click.stop>
                        <input
                            type="checkbox"
                            :checked="selected.includes(entry.id)"
                            class="h-4 w-4 rounded border-slate-300"
                            :aria-label="`Select entry ${entry.reference || entry.id}`"
                            @change="toggleSelected(entry.id, ($event.target as HTMLInputElement).checked)"
                        >
                    </td>
                    <td class="whitespace-nowrap px-3 py-2">{{ entry.date || '-' }}</td>
                    <td class="px-3 py-2">{{ entry.reference || '—' }}</td>
                    <td class="px-3 py-2">{{ entry.description || '—' }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                        {{ entry.debit > 0 ? formatCents(entry.debit) : '—' }}
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                        {{ entry.credit > 0 ? formatCents(entry.credit) : '—' }}
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                        <span :class="entry.is_normal_balance ? 'text-brand-700' : 'text-rose-600'">
                            {{ formatCents(entry.running_balance) }}
                        </span>
                    </td>
                </tr>
                <tr v-if="!entries.data.length">
                    <td colspan="7" class="px-4 py-6">
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
