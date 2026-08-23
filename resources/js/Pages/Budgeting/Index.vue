<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { FolderKanban } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

const props = defineProps({
    budgets: { type: Array, default: () => [] },
    trashed_budgets: { type: Array, default: () => [] },
    /** Still sent for Inertia/tests; detail lives on the budget show page. */
    active_budget: { type: Object, default: null },
    business_currency: { type: String, default: 'ZAR' },
});

const page = usePage();
const canManageBudgets = computed(() => (page.props.team_permissions ?? []).includes('budgets.manage'));

/** In-app delete confirmation (window.confirm is unreliable in some browsers / embedded views). */
const budgetPendingDelete = ref(null);
const budgetDeleteProcessing = ref(false);

const openDeleteBudgetModal = (budget) => {
    budgetPendingDelete.value = budget;
};

const closeDeleteBudgetModal = () => {
    if (budgetDeleteProcessing.value) return;
    budgetPendingDelete.value = null;
};

const confirmDeleteBudget = () => {
    const b = budgetPendingDelete.value;
    if (!b || budgetDeleteProcessing.value) return;
    budgetDeleteProcessing.value = true;
    budgetPendingDelete.value = null;
    router.delete(route('budgeting.destroy', b.id), {
        preserveScroll: true,
        onFinish: () => {
            budgetDeleteProcessing.value = false;
        },
    });
};

/** Trashed budgets: restore or permanently delete */
const budgetPermanentDelete = ref(null);
const budgetPermanentDeleteProcessing = ref(false);

const openPermanentDeleteModal = (row) => {
    budgetPermanentDelete.value = row;
};

const closePermanentDeleteModal = () => {
    if (budgetPermanentDeleteProcessing.value) return;
    budgetPermanentDelete.value = null;
};

const confirmPermanentDeleteBudget = () => {
    const b = budgetPermanentDelete.value;
    if (!b || budgetPermanentDeleteProcessing.value) return;
    budgetPermanentDeleteProcessing.value = true;
    budgetPermanentDelete.value = null;
    router.delete(route('budgeting.force-destroy', b.id), {
        preserveScroll: true,
        onFinish: () => {
            budgetPermanentDeleteProcessing.value = false;
        },
    });
};

const restoreBudget = (row) => {
    router.post(
        route('budgeting.restore', row.id),
        {},
        {
            preserveScroll: true,
        },
    );
};

function formatDeletedAt(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return '—';
    }
}

const formatCents = (cents, currency = 'ZAR') =>
    useFormatCurrency((Number(cents) || 0) / 100, currency || 'ZAR');
</script>

<template>
    <AppLayout
        title="Budgets"
        :breadcrumbs="[{ label: 'Planning' }, { label: 'Budgets' }]"
    >
        <PageHeader
            title="Budgets"
            subtitle="Plan ongoing expenses by category. Optionally limit a budget to a period and track spend against linked accounts."
        >
            <template v-if="canManageBudgets" #actions>
                <AppButton variant="primary" @click="router.visit(route('budgeting.create'))">
                    New budget
                </AppButton>
            </template>
        </PageHeader>

        <EmptyState
            v-if="!(budgets ?? []).length"
            class="mt-5"
            title="No budgets yet"
            :description="canManageBudgets
                ? 'Create a budget, add categories, and plan the expenses you need to cover.'
                : 'Budgets for this business will appear here once someone with access creates one.'"
            :icon="FolderKanban"
        >
            <template v-if="canManageBudgets" #action>
                <AppButton variant="primary" @click="router.visit(route('budgeting.create'))">
                    Create your first budget
                </AppButton>
            </template>
        </EmptyState>

        <AppCard v-else class="mt-5 overflow-hidden p-0">
            <AppTable
                embedded
                dense
                table-class="text-sm"
                :columns="[
                    { key: 'name', label: 'Name' },
                    { key: 'period', label: 'Period' },
                    { key: 'planned', label: 'Planned', align: 'right' },
                    { key: 'spent', label: 'Spent', align: 'right' },
                    { key: 'used', label: '% of plan' },
                    { key: 'status', label: 'Status' },
                    { key: 'actions', label: '' },
                ]"
                :page="1"
                :last-page="1"
                :show-pagination="false"
            >
                <tr
                    v-for="budget in budgets"
                    :key="budget.id"
                    class="cursor-pointer align-middle hover:bg-slate-50"
                    @click="router.visit(route('budgeting.show', budget.id))"
                >
                    <td class="px-4 py-3 font-medium text-slate-900">{{ budget.name }}</td>
                    <td class="px-4 py-3">{{ budget.period }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ formatCents(budget.total_planned, budget.currency) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">
                        <template v-if="budget.has_tracking">
                            {{ formatCents(budget.total_spent, budget.currency) }}
                        </template>
                        <span v-else class="text-slate-400">—</span>
                    </td>
                    <td class="px-4 py-3">
                        <template v-if="budget.has_tracking">{{ budget.percentage_used }}%</template>
                        <span v-else class="text-slate-400">—</span>
                    </td>
                    <td class="px-4 py-3">
                        <AppBadge :variant="budget.status === 'active' ? 'success' : 'neutral'">{{ budget.status }}</AppBadge>
                    </td>
                    <td class="px-4 py-3 text-right" @click.stop>
                        <div v-if="canManageBudgets" class="flex justify-end gap-1">
                            <AppButton size="sm" variant="ghost" @click="router.visit(route('budgeting.edit', budget.id))">
                                Edit details
                            </AppButton>
                            <AppButton
                                size="sm"
                                variant="ghost"
                                class="text-rose-600 hover:text-rose-700"
                                @click="openDeleteBudgetModal(budget)"
                            >
                                Delete
                            </AppButton>
                        </div>
                    </td>
                </tr>
            </AppTable>
        </AppCard>

        <AppCard v-if="canManageBudgets && (trashed_budgets ?? []).length" class="mt-5 overflow-hidden border-dashed border-slate-300 bg-slate-50/50 p-0">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="text-lg font-semibold text-slate-900">Trash</h3>
                <p class="mt-1 text-sm text-slate-500">
                    Budgets you moved to trash can be restored or permanently deleted. Permanent deletion removes all categories and
                    lines.
                </p>
            </div>
            <AppTable
                embedded
                dense
                table-class="text-sm"
                :columns="[
                    { key: 'name', label: 'Name' },
                    { key: 'period', label: 'Period' },
                    { key: 'deleted', label: 'Moved to trash' },
                    { key: 'actions', label: '' },
                ]"
                :page="1"
                :last-page="1"
                :show-pagination="false"
            >
                <tr v-for="row in trashed_budgets" :key="row.id" class="align-middle">
                    <td class="px-4 py-3 font-medium text-slate-900">{{ row.name }}</td>
                    <td class="px-4 py-3 text-slate-600">{{ row.period }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ formatDeletedAt(row.deleted_at) }}</td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex justify-end gap-1">
                            <AppButton size="sm" variant="secondary" @click="restoreBudget(row)">Restore</AppButton>
                            <AppButton
                                size="sm"
                                variant="ghost"
                                class="text-rose-600 hover:text-rose-700"
                                @click.stop="openPermanentDeleteModal(row)"
                            >
                                Delete forever
                            </AppButton>
                        </div>
                    </td>
                </tr>
            </AppTable>
        </AppCard>

        <!-- Move budget to trash confirmation -->
        <div
            v-if="budgetPendingDelete"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="budget-delete-title"
            @click.self="closeDeleteBudgetModal"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h4 id="budget-delete-title" class="text-lg font-semibold text-slate-900">Move budget to trash</h4>
                <p class="mt-2 text-sm text-slate-600">
                    Move
                    <strong class="text-slate-900">“{{ budgetPendingDelete.name }}”</strong>
                    to trash? It will disappear from your budget list until you restore it from Trash below, or delete it forever.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="ghost" :disabled="budgetDeleteProcessing" @click="closeDeleteBudgetModal">
                        Cancel
                    </AppButton>
                    <AppButton
                        variant="danger"
                        :disabled="budgetDeleteProcessing"
                        @click="confirmDeleteBudget"
                    >
                        Move to trash
                    </AppButton>
                </div>
            </div>
        </div>

        <!-- Permanently delete budget from trash -->
        <div
            v-if="budgetPermanentDelete"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="budget-permanent-delete-title"
            @click.self="closePermanentDeleteModal"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h4 id="budget-permanent-delete-title" class="text-lg font-semibold text-slate-900">Delete forever</h4>
                <p class="mt-2 text-sm text-slate-600">
                    Permanently delete
                    <strong class="text-slate-900">“{{ budgetPermanentDelete.name }}”</strong>
                    ? All categories and line items will be removed. This cannot be undone.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="ghost" :disabled="budgetPermanentDeleteProcessing" @click="closePermanentDeleteModal">
                        Cancel
                    </AppButton>
                    <AppButton
                        variant="danger"
                        :disabled="budgetPermanentDeleteProcessing"
                        @click="confirmPermanentDeleteBudget"
                    >
                        Delete forever
                    </AppButton>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
