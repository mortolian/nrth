<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { useBankingTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

const bankingTabs = useBankingTabs();
const page = usePage();

type ReconciliationStatus = 'unreviewed' | 'partially_matched' | 'matched' | 'excluded';

type AccountOption = {
    id: number;
    name: string;
    bank_name: string | null;
    currency: string;
};

type AccountingRecord = {
    id: number;
    type: string;
    type_label: string;
    transaction_date: string | null;
    reference: string | null;
    description: string | null;
    supplier: string | null;
    invoice_number: string | null;
    matchable_cents: number;
    remaining_cents: number;
    context_label: string;
};

type Candidate = AccountingRecord & {
    score: number;
    suggested_amount_cents: number;
};

type Allocation = {
    id: number;
    amount_cents: number;
    note: string | null;
    transaction: AccountingRecord | null;
};

type LineRow = {
    id: number;
    transaction_date: string | null;
    description: string;
    reference: string | null;
    amount_cents: number;
    allocated_cents: number;
    remaining_cents: number;
    currency: string;
    direction: 'debit' | 'credit';
    reconciliation_status: ReconciliationStatus;
    reconciliation_status_label: string;
    account: {
        id: number;
        name: string;
        bank_name: string | null;
    };
};

type SelectedLine = LineRow & {
    exclusion_note: string | null;
    excluded_at: string | null;
    allocations: Allocation[];
    candidates: Candidate[];
};

const props = defineProps<{
    lines: {
        data: LineRow[];
        current_page: number;
        last_page: number;
        total: number;
    };
    selected: SelectedLine | null;
    accounts: AccountOption[];
    filters: {
        status: string;
        from: string | null;
        to: string | null;
        account_id: number | null;
        direction: string | null;
        search: string | null;
        selected: number | null;
    };
    counts: Record<string, number>;
    can_manage: boolean;
}>();

const canManage = computed(() => {
    if (props.can_manage) {
        return true;
    }
    const perms = page.props.team_permissions;
    return Array.isArray(perms) && perms.includes('banking.manage');
});

const filters = ref({
    status: props.filters.status || 'attention',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    account_id: props.filters.account_id ? String(props.filters.account_id) : 'all',
    direction: props.filters.direction ?? 'all',
    search: props.filters.search ?? '',
});

const excludeNote = ref(props.selected?.exclusion_note ?? '');
const customAmount = ref('');

const allocateForm = useForm({
    transaction_id: 0,
    amount_cents: 0,
    note: '',
});

const excludeForm = useForm({
    exclusion_note: '',
});

const formatCents = (cents: number, currency = 'ZAR') =>
    useFormatCurrency((Number(cents) || 0) / 100, currency);

const signedAmount = (row: LineRow) => {
    const signed = row.direction === 'debit' ? -row.amount_cents : row.amount_cents;
    return formatCents(signed, row.currency);
};

const accountLabel = (account: LineRow['account']) =>
    account.bank_name ? `${account.name} (${account.bank_name})` : account.name;

const statusVariant = (status: ReconciliationStatus) => {
    if (status === 'matched') return 'success';
    if (status === 'partially_matched') return 'info';
    if (status === 'excluded') return 'neutral';
    return 'warning';
};

const statusFilters = computed(() => [
    { value: 'attention', label: 'Needs review', count: props.counts.attention ?? 0 },
    { value: 'unreviewed', label: 'Unreviewed', count: props.counts.unreviewed ?? 0 },
    { value: 'partially_matched', label: 'Partial', count: props.counts.partially_matched ?? 0 },
    { value: 'matched', label: 'Matched', count: props.counts.matched ?? 0 },
    { value: 'excluded', label: 'Excluded', count: props.counts.excluded ?? 0 },
    { value: 'all', label: 'All', count: props.counts.all ?? 0 },
]);

const queryPayload = (pageNumber = 1, selectedId: number | null = props.selected?.id ?? props.filters.selected) => ({
    status: filters.value.status || undefined,
    from: filters.value.from || undefined,
    to: filters.value.to || undefined,
    account_id: filters.value.account_id === 'all' ? undefined : filters.value.account_id,
    direction: filters.value.direction === 'all' ? undefined : filters.value.direction,
    search: filters.value.search || undefined,
    selected: selectedId || undefined,
    page: pageNumber,
});

const applyFilters = (pageNumber = 1, selectedId: number | null = props.selected?.id ?? null) => {
    router.get(route('banking.reconciliation.index'), queryPayload(pageNumber, selectedId), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const clearFilters = () => {
    filters.value = { status: 'attention', from: '', to: '', account_id: 'all', direction: 'all', search: '' };
    applyFilters(1, null);
};

const selectLine = (row: LineRow) => {
    applyFilters(props.lines.current_page, row.id);
};

const matchCandidate = (candidate: Candidate) => {
    const override = customAmount.value.trim();
    const amountCents = override !== ''
        ? Math.round(Number(override) * 100)
        : candidate.suggested_amount_cents;

    allocateForm.transaction_id = candidate.id;
    allocateForm.amount_cents = amountCents;
    allocateForm.note = '';
    allocateForm.post(route('banking.reconciliation.allocations.store', props.selected!.id), {
        preserveScroll: true,
        onSuccess: () => {
            customAmount.value = '';
        },
    });
};

const removeAllocation = (allocation: Allocation) => {
    router.delete(
        route('banking.reconciliation.allocations.destroy', {
            bankingTransaction: props.selected!.id,
            allocation: allocation.id,
        }),
        { preserveScroll: true },
    );
};

const excludeLine = () => {
    excludeForm.exclusion_note = excludeNote.value;
    excludeForm.post(route('banking.reconciliation.exclude', props.selected!.id), {
        preserveScroll: true,
    });
};

const resetLine = () => {
    router.post(route('banking.reconciliation.reset', props.selected!.id), {}, { preserveScroll: true });
};
</script>

<template>
    <FeatureShell
        title="Banking"
        section="reconciliation"
        :tabs="bankingTabs"
        document-title="Bank reconciliation"
        subtitle="Match business lines to posted records, or exclude personal / out-of-scope activity."
    >
        <AppCard>
            <p class="text-sm text-slate-600">
                Imported statements can mix personal and business activity. You do not need to match every line.
                Match the business ones, exclude the rest, and leave anything still undecided as unreviewed.
            </p>
            <form class="mt-4" @submit.prevent="applyFilters()">
                <div class="flex flex-wrap gap-2">
                    <AppButton
                        v-for="item in statusFilters"
                        :key="item.value"
                        type="button"
                        size="sm"
                        :variant="filters.status === item.value ? 'primary' : 'secondary'"
                        @click="filters.status = item.value; applyFilters(1, selected?.id ?? null)"
                    >
                        {{ item.label }} ({{ item.count }})
                    </AppButton>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2 lg:grid-cols-5">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">From</label>
                        <AppInput v-model="filters.from" type="date" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">To</label>
                        <AppInput v-model="filters.to" type="date" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Account</label>
                        <AppSelect
                            :model-value="filters.account_id"
                            :options="[
                                { label: 'All accounts', value: 'all' },
                                ...accounts.map((account) => ({
                                    label: account.bank_name ? `${account.name} (${account.bank_name})` : account.name,
                                    value: String(account.id),
                                })),
                            ]"
                            @update:model-value="filters.account_id = $event"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Type</label>
                        <AppSelect
                            :model-value="filters.direction"
                            :options="[
                                { label: 'All', value: 'all' },
                                { label: 'Debit', value: 'debit' },
                                { label: 'Credit', value: 'credit' },
                            ]"
                            @update:model-value="filters.direction = $event"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Search</label>
                        <AppInput v-model="filters.search" placeholder="Description or reference…" />
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <AppButton type="submit" variant="secondary">Apply</AppButton>
                    <AppButton type="button" variant="ghost" @click="clearFilters">Clear</AppButton>
                </div>
            </form>
        </AppCard>

        <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(20rem,0.9fr)]">
            <AppCard>
                <p class="mb-3 text-sm text-slate-500">
                    {{ lines.total }} line{{ lines.total === 1 ? '' : 's' }}
                </p>

                <AppTable
                    v-if="lines.data.length"
                    embedded
                    dense
                    table-class="text-sm"
                    :columns="[
                        { key: 'date', label: 'Date' },
                        { key: 'description', label: 'Description' },
                        { key: 'amount', label: 'Amount', align: 'right' },
                        { key: 'remaining', label: 'Left', align: 'right' },
                        { key: 'status', label: 'Status' },
                    ]"
                    :page="lines.current_page"
                    :last-page="lines.last_page"
                    :show-pagination="lines.last_page > 1"
                    @page-change="applyFilters($event, selected?.id ?? null)"
                >
                    <tr
                        v-for="row in lines.data"
                        :key="row.id"
                        class="cursor-pointer hover:bg-slate-50"
                        :class="selected?.id === row.id ? 'bg-slate-50' : ''"
                        @click="selectLine(row)"
                    >
                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ row.transaction_date }}</td>
                        <td class="max-w-[16rem] px-3 py-2">
                            <div class="truncate font-medium text-slate-900" :title="row.description">{{ row.description }}</div>
                            <div class="truncate text-xs text-slate-500">{{ accountLabel(row.account) }}</div>
                        </td>
                        <td
                            class="whitespace-nowrap px-3 py-2 text-right font-medium tabular-nums"
                            :class="row.direction === 'debit' ? 'text-red-700' : 'text-emerald-700'"
                        >
                            {{ signedAmount(row) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-600">
                            {{ formatCents(row.remaining_cents, row.currency) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <AppBadge :variant="statusVariant(row.reconciliation_status)">
                                {{ row.reconciliation_status_label }}
                            </AppBadge>
                        </td>
                    </tr>
                </AppTable>

                <EmptyState
                    v-else
                    title="Nothing in this queue"
                    description="Import a statement, or switch filters to see matched and excluded lines. Personal activity can stay excluded."
                >
                    <template #action>
                        <AppButton variant="secondary" @click="router.visit(route('banking.import.create'))">
                            Import a statement
                        </AppButton>
                    </template>
                </EmptyState>
            </AppCard>

            <AppCard class="lg:sticky lg:top-4 h-fit">
                <template v-if="selected">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900">{{ selected.description }}</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ selected.transaction_date }} · {{ accountLabel(selected.account) }}
                            </p>
                        </div>
                        <AppBadge :variant="statusVariant(selected.reconciliation_status)">
                            {{ selected.reconciliation_status_label }}
                        </AppBadge>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-slate-500">Amount</dt>
                            <dd class="font-medium tabular-nums" :class="selected.direction === 'debit' ? 'text-red-700' : 'text-emerald-700'">
                                {{ signedAmount(selected) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Remaining</dt>
                            <dd class="font-medium tabular-nums">{{ formatCents(selected.remaining_cents, selected.currency) }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-xs text-slate-500">Reference</dt>
                            <dd>{{ selected.reference || '—' }}</dd>
                        </div>
                    </dl>

                    <div v-if="selected.allocations.length" class="mt-5">
                        <h3 class="text-sm font-semibold text-slate-900">Allocations</h3>
                        <ul class="mt-2 space-y-2">
                            <li
                                v-for="allocation in selected.allocations"
                                :key="allocation.id"
                                class="rounded-md border border-slate-200 px-3 py-2 text-sm"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-medium text-slate-900">
                                            {{ allocation.transaction?.context_label ?? 'Accounting transaction' }}
                                        </p>
                                        <p class="text-xs text-slate-500">
                                            {{ allocation.transaction?.transaction_date }}
                                            · {{ formatCents(allocation.amount_cents, selected.currency) }}
                                        </p>
                                    </div>
                                    <AppButton
                                        v-if="canManage"
                                        variant="ghost"
                                        size="sm"
                                        @click="removeAllocation(allocation)"
                                    >
                                        Remove
                                    </AppButton>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div v-if="canManage && selected.reconciliation_status !== 'matched'" class="mt-5">
                        <h3 class="text-sm font-semibold text-slate-900">Suggested matches</h3>
                        <p class="mt-1 text-xs text-slate-500">
                            Split a bank line across more than one posted payment, expense, or journal entry if needed.
                        </p>
                        <div class="mt-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Amount override (optional)</label>
                            <AppInput v-model="customAmount" placeholder="Leave blank to use remaining" inputmode="decimal" />
                        </div>
                        <ul v-if="selected.candidates.length" class="mt-3 space-y-2">
                            <li
                                v-for="candidate in selected.candidates"
                                :key="candidate.id"
                                class="rounded-md border border-slate-200 px-3 py-2 text-sm"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-medium text-slate-900">{{ candidate.context_label }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ candidate.transaction_date }}
                                            · left {{ formatCents(candidate.remaining_cents, selected.currency) }}
                                        </p>
                                        <p v-if="candidate.description" class="mt-1 text-xs text-slate-600">
                                            {{ candidate.description }}
                                        </p>
                                    </div>
                                    <AppButton
                                        variant="secondary"
                                        size="sm"
                                        :loading="allocateForm.processing && allocateForm.transaction_id === candidate.id"
                                        @click="matchCandidate(candidate)"
                                    >
                                        Match
                                    </AppButton>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-sm text-slate-500">
                            No posted candidates in the nearby date window. You can still exclude this line if it is personal.
                        </p>
                    </div>

                    <div v-if="canManage" class="mt-5 border-t border-slate-200 pt-4">
                        <template v-if="selected.reconciliation_status === 'excluded'">
                            <p class="text-sm text-slate-600">
                                Excluded as personal / not business{{ selected.exclusion_note ? `: ${selected.exclusion_note}` : '' }}.
                            </p>
                            <AppButton class="mt-3" variant="secondary" @click="resetLine">
                                Mark unreviewed
                            </AppButton>
                        </template>
                        <template v-else>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Exclusion note (optional)</label>
                            <AppInput v-model="excludeNote" placeholder="e.g. Personal groceries" />
                            <div class="mt-3 flex flex-wrap gap-2">
                                <AppButton
                                    variant="secondary"
                                    :loading="excludeForm.processing"
                                    @click="excludeLine"
                                >
                                    Exclude as personal
                                </AppButton>
                                <AppButton
                                    v-if="selected.reconciliation_status !== 'unreviewed'"
                                    variant="ghost"
                                    @click="resetLine"
                                >
                                    Reset to unreviewed
                                </AppButton>
                            </div>
                        </template>
                    </div>
                </template>

                <EmptyState
                    v-else
                    title="Select a bank line"
                    description="Choose a row to match it to posted business records or exclude it from the books."
                />
            </AppCard>
        </div>
    </FeatureShell>
</template>
