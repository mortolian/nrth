<script setup lang="ts">
import { computed, ref } from 'vue';
import { ArrowUpDown } from 'lucide-vue-next';

export type TableColumn = {
    key: string;
    label: string;
    sortable?: boolean;
    widthClass?: string;
};

const props = withDefaults(defineProps<{
    columns?: string[] | TableColumn[];
    page?: number;
    lastPage?: number;
    loading?: boolean;
    clickableRows?: boolean;
    /** Merged onto `<table>` (e.g. `min-w-[920px]` so dense tables scroll instead of wrapping). */
    tableClass?: string;
    /** When false, hides the page footer (e.g. embedded lists with a single page). */
    showPagination?: boolean;
    /** Called with the data `<tbody>` when the slot body is mounted (or `null` when unmounted). For row Sortable. */
    tbodyRefFn?: (el: HTMLTableSectionElement | null) => void;
}>(), {
    columns: () => [],
    page: 1,
    lastPage: 1,
    loading: false,
    clickableRows: false,
    tableClass: '',
    showPagination: true,
});

function setDataTbodyRef(el: unknown) {
    props.tbodyRefFn?.(el instanceof HTMLTableSectionElement ? el : null);
}

const emit = defineEmits<{
    (e: 'sort', payload: { key: string; direction: 'asc' | 'desc' }): void;
    (e: 'row-click', row: unknown): void;
    (e: 'page-change', page: number): void;
}>();

const sortBy = ref<string | null>(null);
const sortDirection = ref<'asc' | 'desc'>('asc');

const normalizedColumns = computed<TableColumn[]>(() => {
    if (!props.columns?.length) return [];
    if (typeof props.columns[0] === 'string') {
        return (props.columns as string[]).map((label) => ({ key: label, label }));
    }
    return props.columns as TableColumn[];
});

const onSort = (column: TableColumn) => {
    if (!column.sortable) return;
    if (sortBy.value === column.key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortBy.value = column.key;
        sortDirection.value = 'asc';
    }
    emit('sort', { key: column.key, direction: sortDirection.value });
};

const nextPage = () => {
    if (props.page < props.lastPage) emit('page-change', props.page + 1);
};

const prevPage = () => {
    if (props.page > 1) emit('page-change', props.page - 1);
};
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="overflow-x-auto">
            <table :class="['min-w-full divide-y divide-slate-200', tableClass]">
                <thead v-if="normalizedColumns.length" class="bg-slate-50">
                    <tr>
                        <th
                            v-for="column in normalizedColumns"
                            :key="column.key"
                            :class="[column.widthClass ?? '', 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500']"
                        >
                            <button
                                v-if="column.sortable"
                                type="button"
                                class="inline-flex items-center gap-1 hover:text-slate-700"
                                @click="onSort(column)"
                            >
                                {{ column.label }}
                                <ArrowUpDown class="h-3.5 w-3.5" />
                            </button>
                            <span v-else>{{ column.label }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody v-if="loading" class="divide-y divide-slate-100">
                    <tr v-for="row in 5" :key="`skeleton-${row}`">
                        <td
                            v-for="column in Math.max(1, normalizedColumns.length)"
                            :key="`skeleton-${row}-${column}`"
                            class="px-4 py-3"
                        >
                            <div class="h-4 animate-pulse rounded bg-slate-100" />
                        </td>
                    </tr>
                </tbody>
                <tbody v-else :ref="setDataTbodyRef" class="divide-y divide-slate-100">
                    <slot />
                </tbody>
            </table>
        </div>
        <div
            v-if="showPagination"
            class="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-xs text-slate-500"
        >
            <p>Page {{ page }} of {{ lastPage }}</p>
            <div class="flex items-center gap-2">
                <button class="rounded border border-slate-200 px-2 py-1 hover:bg-slate-50 disabled:opacity-50" :disabled="page <= 1" @click="prevPage">Previous</button>
                <button class="rounded border border-slate-200 px-2 py-1 hover:bg-slate-50 disabled:opacity-50" :disabled="page >= lastPage" @click="nextPage">Next</button>
            </div>
        </div>
    </div>
</template>
