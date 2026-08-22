<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { FolderKanban, Plus } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import BudgetCategoryModal from '@/Components/BudgetCategoryModal.vue';
import BudgetItemModal from '@/Components/BudgetItemModal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InvoiceRowActionsMenu from '@/Components/InvoiceRowActionsMenu.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { useToast } from '@/Composables/useToast';

type BudgetItemRow = {
    id: number;
    label: string;
    cadence: 'monthly' | 'annually' | 'once_per_period';
    notes: string | null;
    monthly_amount_cents: number;
    currency: string;
    fx_budget_per_line_major: string | null;
    monthly_budget_currency_cents: number;
    amount_budget_currency_cents: number;
    period_total_budget_cents: number;
    annualized_budget_cents: number;
};

type BudgetCategoryRow = {
    id: number;
    name: string;
    account_id: number | null;
    account_name: string | null;
    period_planned_cents: number;
    monthly_planned_cents: number;
    spent_cents: number;
    has_account: boolean;
    percentage: number;
    remaining_cents: number;
    items: BudgetItemRow[];
};

const props = defineProps<{
    budget: {
        id: number;
        name: string;
        period_type: string | null;
        period: string;
        has_period: boolean;
        start_date: string | null;
        end_date: string | null;
        currency: string;
        is_active: boolean;
        months_in_period: number;
        total_planned: number;
        total_monthly_planned: number;
        has_tracking: boolean;
        total_spent: number;
        percentage_used: number;
        business_spend_aligned: boolean;
        categories: BudgetCategoryRow[];
    };
    expense_accounts: Array<{ id: number; name: string }>;
    can_import_structure: boolean;
    business_currency: string;
}>();

const page = usePage();
const toast = useToast();
const canManage = computed(() => (page.props.team_permissions ?? []).includes('budgets.manage'));

const formatCents = (cents: number, currency = 'ZAR') =>
    useFormatCurrency((Number(cents) || 0) / 100, currency || 'ZAR');

const progressColor = (percentage: number) => {
    if (percentage >= 100) return 'bg-rose-500';
    if (percentage >= 80) return 'bg-amber-500';
    return 'bg-brand-500';
};

const categoryModalOpen = ref(false);
const categoryEditing = ref<BudgetCategoryRow | null>(null);

const itemModalOpen = ref(false);
const itemCategoryId = ref<number | null>(null);
const itemEditing = ref<BudgetItemRow | null>(null);

const deleteBudgetOpen = ref(false);
const deleteBudgetProcessing = ref(false);
const pendingDeleteCategory = ref<BudgetCategoryRow | null>(null);
const pendingDeleteItem = ref<{ category: BudgetCategoryRow; item: BudgetItemRow } | null>(null);
const deleteProcessing = ref(false);
const importProcessing = ref(false);

const openAddCategory = () => {
    categoryEditing.value = null;
    categoryModalOpen.value = true;
};

const openEditCategory = (cat: BudgetCategoryRow) => {
    categoryEditing.value = cat;
    categoryModalOpen.value = true;
};

const openAddItem = (cat: BudgetCategoryRow) => {
    itemCategoryId.value = cat.id;
    itemEditing.value = null;
    itemModalOpen.value = true;
};

const openEditItem = (cat: BudgetCategoryRow, item: BudgetItemRow) => {
    itemCategoryId.value = cat.id;
    itemEditing.value = item;
    itemModalOpen.value = true;
};

const confirmDeleteCategory = () => {
    const cat = pendingDeleteCategory.value;
    if (!cat || deleteProcessing.value) return;
    deleteProcessing.value = true;
    router.delete(route('budgeting.categories.destroy', [props.budget.id, cat.id]), {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Category deleted.');
            pendingDeleteCategory.value = null;
        },
        onError: () => toast.error('Could not delete this category.'),
        onFinish: () => {
            deleteProcessing.value = false;
        },
    });
};

const confirmDeleteItem = () => {
    const pending = pendingDeleteItem.value;
    if (!pending || deleteProcessing.value) return;
    deleteProcessing.value = true;
    router.delete(
        route('budgeting.items.destroy', [props.budget.id, pending.category.id, pending.item.id]),
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Line item deleted.');
                pendingDeleteItem.value = null;
            },
            onError: () => toast.error('Could not delete this line item.'),
            onFinish: () => {
                deleteProcessing.value = false;
            },
        },
    );
};

const confirmDeleteBudget = () => {
    if (deleteBudgetProcessing.value) return;
    deleteBudgetProcessing.value = true;
    router.delete(route('budgeting.destroy', props.budget.id), {
        onFinish: () => {
            deleteBudgetProcessing.value = false;
        },
    });
};

const importStructure = () => {
    if (importProcessing.value || !props.can_import_structure) return;
    importProcessing.value = true;
    router.post(
        route('budgeting.import-structure', props.budget.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                importProcessing.value = false;
            },
        },
    );
};

const onCategoryAction = (cat: BudgetCategoryRow, actionId: string) => {
    if (actionId === 'edit') openEditCategory(cat);
    if (actionId === 'delete') pendingDeleteCategory.value = cat;
};

const onItemAction = (cat: BudgetCategoryRow, item: BudgetItemRow, actionId: string) => {
    if (actionId === 'edit') openEditItem(cat, item);
    if (actionId === 'delete') pendingDeleteItem.value = { category: cat, item };
};

const categoryActions = [
    { id: 'edit', label: 'Edit category' },
    { id: 'delete', label: 'Delete category' },
];

const itemActions = [
    { id: 'edit', label: 'Edit line' },
    { id: 'delete', label: 'Delete line' },
];
</script>

<template>
    <AppLayout
        :title="budget.name"
        :breadcrumbs="[
            { label: 'Planning' },
            { label: 'Budgets', href: route('budgeting.index') },
            { label: budget.name },
        ]"
    >
        <PageHeader :title="budget.name" :subtitle="budget.period">
            <template #actions>
                <AppBadge v-if="budget.is_active" variant="success">Active</AppBadge>
                <AppBadge v-else variant="neutral">Inactive</AppBadge>
                <template v-if="canManage">
                    <AppButton variant="secondary" @click="router.visit(route('budgeting.edit', budget.id))">
                        Edit details
                    </AppButton>
                    <AppButton
                        v-if="can_import_structure"
                        variant="ghost"
                        :disabled="importProcessing"
                        @click="importStructure"
                    >
                        Copy structure
                    </AppButton>
                    <AppButton variant="ghost" class="text-rose-600 hover:text-rose-700" @click="deleteBudgetOpen = true">
                        Delete
                    </AppButton>
                </template>
            </template>
        </PageHeader>

        <div
            class="mt-2 grid gap-4 sm:grid-cols-2"
            :class="budget.has_tracking ? (budget.has_period ? 'xl:grid-cols-4' : 'xl:grid-cols-3') : budget.has_period ? 'xl:grid-cols-2' : 'xl:grid-cols-1'"
        >
            <AppCard v-if="budget.has_period">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Planned (period)</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">
                    {{ formatCents(budget.total_planned, budget.currency) }}
                </p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Monthly planned</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">
                    {{ formatCents(budget.total_monthly_planned, budget.currency) }}
                </p>
            </AppCard>
            <AppCard v-if="budget.has_tracking">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Spent (tracked)</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">
                    {{ formatCents(budget.total_spent, budget.currency) }}
                </p>
                <p v-if="!budget.business_spend_aligned" class="mt-1 text-xs text-amber-700">
                    Linked categories only (budget {{ budget.currency }} ≠ books {{ business_currency }}).
                </p>
            </AppCard>
            <AppCard v-if="budget.has_tracking">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Used vs plan</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ budget.percentage_used }}%</p>
                <div class="mt-3 h-2 w-full rounded-full bg-slate-100">
                    <div
                        :class="progressColor(budget.percentage_used)"
                        class="h-2 rounded-full"
                        :style="{ width: `${Math.min(100, budget.percentage_used)}%` }"
                    />
                </div>
            </AppCard>
        </div>

        <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Categories</h2>
                <p class="mt-0.5 text-sm text-slate-500">
                    <template v-if="budget.has_period">
                        Planned expenses for this period ({{ budget.months_in_period }} month(s)).
                    </template>
                    <template v-else>
                        Ongoing monthly plan. Tracked spend uses the current calendar month.
                    </template>
                </p>
            </div>
            <AppButton v-if="canManage" variant="primary" @click="openAddCategory">
                <Plus class="mr-1.5 h-4 w-4" aria-hidden="true" />
                Add category
            </AppButton>
        </div>

        <EmptyState
            v-if="!(budget.categories ?? []).length"
            class="mt-4"
            title="No categories yet"
            :description="
                canManage
                    ? 'Add categories, then add the expenses you need to plan for — or copy the structure from your previous budget.'
                    : 'Categories will appear here once someone with access builds this plan.'
            "
            :icon="FolderKanban"
        >
            <template v-if="canManage" #action>
                <div class="flex flex-wrap justify-center gap-2">
                    <AppButton variant="primary" @click="openAddCategory">Add category</AppButton>
                    <AppButton
                        v-if="can_import_structure"
                        variant="secondary"
                        :disabled="importProcessing"
                        @click="importStructure"
                    >
                        Copy structure from last budget
                    </AppButton>
                </div>
            </template>
        </EmptyState>

        <div v-else class="mt-4 space-y-4">
            <AppCard v-for="cat in budget.categories" :key="cat.id">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-3">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-slate-900">{{ cat.name }}</h3>
                        <p class="mt-0.5 text-sm text-slate-500">
                            Planned {{ formatCents(cat.period_planned_cents, budget.currency) }}
                            <span v-if="cat.account_name"> · Tracking {{ cat.account_name }}</span>
                            <span v-else> · Not tracking spend</span>
                        </p>
                    </div>
                    <div v-if="canManage" class="flex items-center gap-2">
                        <AppButton size="sm" variant="secondary" @click="openAddItem(cat)">
                            Add line
                        </AppButton>
                        <InvoiceRowActionsMenu
                            :actions="categoryActions"
                            :aria-label="`Actions for ${cat.name}`"
                            @select="onCategoryAction(cat, $event)"
                        />
                    </div>
                </div>

                <AppTable
                    class="mt-3"
                    embedded
                    dense
                    table-class="text-sm"
                    :columns="[
                        { key: 'label', label: 'Expense' },
                        { key: 'cadence', label: 'Cadence' },
                        { key: 'ccy', label: 'Currency' },
                        { key: 'amount_line', label: 'Amount (line)', align: 'right' },
                        { key: 'monthly_budget', label: `Monthly (${budget.currency})`, align: 'right' },
                        { key: 'period', label: 'Period total', align: 'right' },
                        { key: 'actions', label: '', widthClass: 'w-12', align: 'right' },
                    ]"
                    :page="1"
                    :last-page="1"
                    :show-pagination="false"
                >
                    <tr v-if="!(cat.items ?? []).length">
                        <td colspan="7" class="px-3 py-4 text-sm text-slate-500">
                            No planned expenses yet.
                            <button
                                v-if="canManage"
                                type="button"
                                class="ml-1 font-medium text-brand-700 hover:underline"
                                @click="openAddItem(cat)"
                            >
                                Add a line
                            </button>
                        </td>
                    </tr>
                    <tr
                        v-for="item in cat.items"
                        :key="item.id"
                        class="align-middle"
                    >
                        <td class="px-3 py-2 text-slate-900">
                            <div>{{ item.label }}</div>
                            <p v-if="item.notes" class="mt-0.5 max-w-xs text-xs font-normal text-slate-500">
                                {{ item.notes }}
                            </p>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <AppBadge
                                :variant="
                                    item.cadence === 'once_per_period'
                                        ? 'warning'
                                        : item.cadence === 'annually'
                                          ? 'info'
                                          : 'neutral'
                                "
                            >
                                {{
                                    item.cadence === 'once_per_period'
                                        ? 'Once'
                                        : item.cadence === 'annually'
                                          ? 'Annually'
                                          : 'Monthly'
                                }}
                            </AppBadge>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-600">{{ item.currency }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                            {{ formatCents(item.monthly_amount_cents, item.currency) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                            {{ formatCents(item.monthly_budget_currency_cents, budget.currency) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                            {{ formatCents(item.period_total_budget_cents, budget.currency) }}
                        </td>
                        <td class="px-3 py-2 text-right" @click.stop>
                            <InvoiceRowActionsMenu
                                v-if="canManage"
                                :actions="itemActions"
                                :aria-label="`Actions for ${item.label}`"
                                @select="onItemAction(cat, item, $event)"
                            />
                        </td>
                    </tr>
                    <tr class="border-t border-slate-200 bg-slate-50/80 font-medium text-slate-900">
                        <td class="px-3 py-2" colspan="4">Category total</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                            {{ formatCents(cat.monthly_planned_cents, budget.currency) }}
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                            {{ formatCents(cat.period_planned_cents, budget.currency) }}
                        </td>
                        <td class="px-3 py-2" />
                    </tr>
                </AppTable>

                <div
                    v-if="cat.has_account"
                    class="mt-3 space-y-2"
                >
                    <div class="flex flex-wrap gap-4 text-sm text-slate-600">
                        <span>
                            Spent vs plan:
                            <span class="font-medium tabular-nums text-slate-900">
                                {{ formatCents(cat.spent_cents, budget.currency) }}
                            </span>
                            /
                            <span class="font-medium tabular-nums text-slate-900">
                                {{ formatCents(cat.period_planned_cents, budget.currency) }}
                            </span>
                        </span>
                        <span>
                            Remaining:
                            <span class="font-medium tabular-nums text-slate-900">
                                {{ formatCents(cat.remaining_cents, budget.currency) }}
                            </span>
                        </span>
                        <span class="tabular-nums">{{ cat.percentage }}%</span>
                    </div>
                    <div class="h-2 w-full rounded-full bg-slate-100">
                        <div
                            :class="progressColor(cat.percentage)"
                            class="h-2 rounded-full"
                            :style="{ width: `${Math.min(100, cat.percentage)}%` }"
                        />
                    </div>
                </div>
            </AppCard>
        </div>

        <BudgetCategoryModal
            :show="categoryModalOpen"
            :budget-id="budget.id"
            :budget-currency="budget.currency"
            :category="categoryEditing"
            :expense-accounts="expense_accounts"
            @close="categoryModalOpen = false"
        />

        <BudgetItemModal
            :show="itemModalOpen"
            :budget-id="budget.id"
            :category-id="itemCategoryId"
            :budget-currency="budget.currency"
            :item="itemEditing"
            @close="itemModalOpen = false"
        />

        <!-- Delete budget -->
        <div
            v-if="deleteBudgetOpen"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="!deleteBudgetProcessing && (deleteBudgetOpen = false)"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h4 class="text-lg font-semibold text-slate-900">Move budget to trash</h4>
                <p class="mt-2 text-sm text-slate-600">
                    Move <strong class="text-slate-900">“{{ budget.name }}”</strong> to trash?
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="ghost" :disabled="deleteBudgetProcessing" @click="deleteBudgetOpen = false">
                        Cancel
                    </AppButton>
                    <AppButton
                        variant="danger"
                        :disabled="deleteBudgetProcessing"
                        @click="confirmDeleteBudget"
                    >
                        Move to trash
                    </AppButton>
                </div>
            </div>
        </div>

        <!-- Delete category -->
        <div
            v-if="pendingDeleteCategory"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="!deleteProcessing && (pendingDeleteCategory = null)"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h4 class="text-lg font-semibold text-slate-900">Delete category</h4>
                <p class="mt-2 text-sm text-slate-600">
                    Delete <strong class="text-slate-900">“{{ pendingDeleteCategory.name }}”</strong> and all of its
                    line items? This cannot be undone.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="ghost" :disabled="deleteProcessing" @click="pendingDeleteCategory = null">
                        Cancel
                    </AppButton>
                    <AppButton variant="danger" :disabled="deleteProcessing" @click="confirmDeleteCategory">
                        Delete category
                    </AppButton>
                </div>
            </div>
        </div>

        <!-- Delete item -->
        <div
            v-if="pendingDeleteItem"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            @click.self="!deleteProcessing && (pendingDeleteItem = null)"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h4 class="text-lg font-semibold text-slate-900">Delete line item</h4>
                <p class="mt-2 text-sm text-slate-600">
                    Delete <strong class="text-slate-900">“{{ pendingDeleteItem.item.label }}”</strong>?
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="ghost" :disabled="deleteProcessing" @click="pendingDeleteItem = null">
                        Cancel
                    </AppButton>
                    <AppButton variant="danger" :disabled="deleteProcessing" @click="confirmDeleteItem">
                        Delete line
                    </AppButton>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
