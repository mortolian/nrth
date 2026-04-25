import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

export type PaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    path?: string;
};

export function usePagination(meta?: PaginationMeta) {
    const currentPage = ref(meta?.current_page ?? 1);
    const perPage = ref(meta?.per_page ?? 15);
    const total = ref(meta?.total ?? 0);
    const lastPage = computed(() => meta?.last_page ?? 1);

    const visit = (page: number) => {
        const target = meta?.path ?? window.location.pathname;
        router.get(target, { page }, { preserveState: true, preserveScroll: true, replace: true });
        currentPage.value = page;
    };

    const next = () => {
        if (currentPage.value < lastPage.value) {
            visit(currentPage.value + 1);
        }
    };

    const previous = () => {
        if (currentPage.value > 1) {
            visit(currentPage.value - 1);
        }
    };

    return {
        currentPage,
        perPage,
        total,
        lastPage,
        visit,
        next,
        previous,
    };
}
