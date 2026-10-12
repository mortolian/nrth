<script setup lang="ts">
import { computed } from 'vue';
import { ChevronDown } from 'lucide-vue-next';

const props = withDefaults(defineProps<{
    page?: number;
    lastPage?: number;
    perPage?: number;
    total?: number;
    pageSizes?: number[];
}>(), {
    page: 1,
    lastPage: 1,
    perPage: 15,
    total: 0,
    pageSizes: () => [10, 15, 25, 50, 100],
});

const emit = defineEmits<{
    (e: 'page-change', page: number): void;
    (e: 'per-page-change', perPage: number): void;
}>();

const safeLastPage = computed(() => Math.max(1, props.lastPage));
const safePage = computed(() => Math.min(Math.max(1, props.page), safeLastPage.value));

const rangeStart = computed(() => (props.total === 0 ? 0 : (safePage.value - 1) * props.perPage + 1));
const rangeEnd = computed(() => (props.total === 0 ? 0 : Math.min(safePage.value * props.perPage, props.total)));

const pageItems = computed(() => pageWindow(safePage.value, safeLastPage.value));
const pageChoices = computed(() => Array.from({ length: safeLastPage.value }, (_, index) => index + 1));

const controlClass = 'h-8 min-w-[5rem] cursor-pointer appearance-none rounded border border-slate-200 bg-white bg-none py-0 ps-3 pe-8 text-xs leading-8 tabular-nums text-slate-700';
const stepClass = 'inline-flex h-8 items-center rounded border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40';

function goTo(page: number) {
    if (page < 1 || page > safeLastPage.value || page === safePage.value) {
        return;
    }
    emit('page-change', page);
}

function onPageSelect(event: Event) {
    goTo(Number((event.target as HTMLSelectElement).value));
}

function onPerPageSelect(event: Event) {
    const next = Number((event.target as HTMLSelectElement).value);
    if (next !== props.perPage) {
        emit('per-page-change', next);
    }
}

function pageWindow(current: number, last: number): Array<number | 'gap'> {
    if (last <= 7) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    const wanted = new Set<number>([1, last, current - 1, current, current + 1]);
    if (current <= 3) {
        wanted.add(2);
        wanted.add(3);
        wanted.add(4);
    }
    if (current >= last - 2) {
        wanted.add(last - 1);
        wanted.add(last - 2);
        wanted.add(last - 3);
    }

    const sorted = [...wanted].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b);
    const items: Array<number | 'gap'> = [];
    sorted.forEach((page, index) => {
        if (index > 0 && page - sorted[index - 1] > 1) {
            items.push('gap');
        }
        items.push(page);
    });

    return items;
}
</script>

<template>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-500">
            <p class="tabular-nums">
                Showing {{ rangeStart }}–{{ rangeEnd }} of {{ total }}
            </p>
            <label class="inline-flex items-center gap-2">
                <span>Rows</span>
                <span class="relative inline-flex">
                    <select
                        :class="controlClass"
                        :value="String(perPage)"
                        aria-label="Rows per page"
                        @change="onPerPageSelect"
                    >
                        <option v-for="size in pageSizes" :key="size" :value="String(size)">{{ size }}</option>
                    </select>
                    <ChevronDown class="pointer-events-none absolute inset-y-0 right-2.5 my-auto h-3.5 w-3.5 text-slate-400" aria-hidden="true" />
                </span>
            </label>
        </div>

        <div class="flex flex-wrap items-center gap-1.5">
            <button type="button" :class="stepClass" :disabled="safePage <= 1" @click="goTo(safePage - 1)">
                Previous
            </button>
            <template v-for="(item, index) in pageItems" :key="`${item}-${index}`">
                <span v-if="item === 'gap'" class="px-1 text-xs text-slate-400">…</span>
                <button
                    v-else
                    type="button"
                    class="inline-flex h-8 min-w-8 items-center justify-center rounded border px-2 text-xs font-medium tabular-nums"
                    :class="item === safePage
                        ? 'border-brand-600 bg-brand-600 text-white'
                        : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                    :aria-current="item === safePage ? 'page' : undefined"
                    @click="goTo(item)"
                >
                    {{ item }}
                </button>
            </template>
            <button type="button" :class="stepClass" :disabled="safePage >= safeLastPage" @click="goTo(safePage + 1)">
                Next
            </button>
            <label class="ml-1 inline-flex items-center gap-2 text-xs text-slate-500">
                <span>Page</span>
                <span class="relative inline-flex">
                    <select
                        :class="controlClass"
                        :value="String(safePage)"
                        aria-label="Go to page"
                        @change="onPageSelect"
                    >
                        <option v-for="choice in pageChoices" :key="choice" :value="String(choice)">{{ choice }}</option>
                    </select>
                    <ChevronDown class="pointer-events-none absolute inset-y-0 right-2.5 my-auto h-3.5 w-3.5 text-slate-400" aria-hidden="true" />
                </span>
            </label>
        </div>
    </div>
</template>
