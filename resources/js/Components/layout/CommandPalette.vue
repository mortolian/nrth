<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    DialogContent,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'radix-vue';
import { FileText, Plus, Receipt, Search, UserRound } from 'lucide-vue-next';

type PaletteAction = { id: string; label: string; href?: string; icon?: 'invoice' | 'expense' | 'payment' | 'client' };
type PaletteRecentItem = { id: string | number; label: string; subtitle?: string; href?: string };
type PaletteNavigationItem = { id: string; label: string; href?: string };
type PaletteData = {
    quickActions?: PaletteAction[];
    navigation?: PaletteNavigationItem[];
    recent?: {
        invoices?: PaletteRecentItem[];
        clients?: PaletteRecentItem[];
        transactions?: PaletteRecentItem[];
    };
};

type FlatItem = {
    id: string;
    group: 'Quick Actions' | 'Navigation' | 'Recent';
    label: string;
    subtitle?: string;
    href?: string;
    kind: 'quick' | 'navigation' | 'recent';
    icon?: PaletteAction['icon'];
};

const open = defineModel<boolean>('open', { default: false });

const props = withDefaults(defineProps<{
    data?: PaletteData;
}>(), {
    data: () => ({}),
});

const query = ref('');
const selectedIndex = ref(0);

const iconForQuickAction = (icon?: PaletteAction['icon']) => {
    switch (icon) {
        case 'invoice': return FileText;
        case 'expense': return Receipt;
        case 'payment': return Plus;
        case 'client': return UserRound;
        default: return Search;
    }
};

const baseItems = computed<FlatItem[]>(() => {
    const list: FlatItem[] = [];

    (props.data.quickActions ?? []).forEach((item) => {
        list.push({
            id: `quick-${item.id}`,
            group: 'Quick Actions',
            label: item.label,
            href: item.href,
            kind: 'quick',
            icon: item.icon,
        });
    });

    (props.data.navigation ?? []).forEach((item) => {
        list.push({
            id: `nav-${item.id}`,
            group: 'Navigation',
            label: item.label,
            href: item.href,
            kind: 'navigation',
        });
    });

    const recents = props.data.recent ?? {};
    [...(recents.invoices ?? []), ...(recents.clients ?? []), ...(recents.transactions ?? [])].forEach((item, i) => {
        list.push({
            id: `recent-${item.id}-${i}`,
            group: 'Recent',
            label: item.label,
            subtitle: item.subtitle,
            href: item.href,
            kind: 'recent',
        });
    });

    return list;
});

const fuzzyScore = (needle: string, haystack: string): number => {
    if (!needle.trim()) return 1;
    const n = needle.toLowerCase();
    const h = haystack.toLowerCase();
    if (h.includes(n)) return 200 - h.indexOf(n);
    let j = 0;
    let score = 0;
    for (let i = 0; i < h.length && j < n.length; i++) {
        if (h[i] === n[j]) {
            score += 3;
            j++;
        }
    }
    return j === n.length ? score : 0;
};

const visibleItems = computed(() => {
    const q = query.value.trim();
    const filtered = baseItems.value
        .map((item) => ({
            item,
            score: fuzzyScore(q, `${item.label} ${item.subtitle ?? ''}`),
        }))
        .filter((entry) => entry.score > 0)
        .sort((a, b) => b.score - a.score)
        .map((entry) => entry.item);

    return filtered;
});

const groupedItems = computed(() => ({
    quick: visibleItems.value.filter((item) => item.group === 'Quick Actions'),
    recent: visibleItems.value.filter((item) => item.group === 'Recent'),
    navigation: visibleItems.value.filter((item) => item.group === 'Navigation'),
}));

watch(visibleItems, () => {
    if (selectedIndex.value >= visibleItems.value.length) {
        selectedIndex.value = Math.max(0, visibleItems.value.length - 1);
    }
});

watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
        selectedIndex.value = 0;
    }
});

const navigateToItem = (item?: FlatItem) => {
    if (!item || !item.href || item.href === '#') return;
    open.value = false;
    router.visit(item.href);
};

const onKeyDown = (event: KeyboardEvent) => {
    if (!open.value) return;

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        if (!visibleItems.value.length) return;
        selectedIndex.value = (selectedIndex.value + 1) % visibleItems.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (!visibleItems.value.length) return;
        selectedIndex.value = (selectedIndex.value - 1 + visibleItems.value.length) % visibleItems.value.length;
    } else if (event.key === 'Enter') {
        event.preventDefault();
        navigateToItem(visibleItems.value[selectedIndex.value]);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        open.value = false;
    }
};

onMounted(() => window.addEventListener('keydown', onKeyDown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeyDown));
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-[70] bg-black/50 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in-0 data-[state=closed]:fade-out-0" />
            <DialogContent class="fixed left-1/2 top-24 z-[80] w-[95vw] max-w-2xl -translate-x-1/2 rounded-xl border border-slate-200 bg-white shadow-2xl data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in-0 data-[state=closed]:fade-out-0 data-[state=open]:zoom-in-95 data-[state=closed]:zoom-out-95">
                <DialogTitle class="sr-only">Command Palette</DialogTitle>

                <div class="flex items-center gap-2 border-b border-slate-200 px-4 py-3">
                    <Search class="h-4 w-4 text-slate-500" />
                    <input
                        v-model="query"
                        autofocus
                        type="text"
                        placeholder="Search pages, invoices, clients, transactions..."
                        class="w-full border-0 bg-transparent text-sm text-slate-800 outline-none placeholder:text-slate-400"
                    >
                </div>

                <div class="max-h-[60vh] overflow-y-auto p-2">
                    <template v-if="visibleItems.length">
                        <div v-if="groupedItems.quick.length" class="mb-3">
                            <p class="px-2 py-1 text-xs font-medium uppercase tracking-wide text-slate-500">Quick Actions</p>
                            <button
                                v-for="(item, idx) in groupedItems.quick"
                                :key="item.id"
                                type="button"
                                :class="[
                                    'flex w-full items-center justify-between rounded-md px-3 py-2 text-left transition',
                                    visibleItems[selectedIndex]?.id === item.id ? 'bg-brand-50 text-brand-900' : 'hover:bg-slate-50',
                                ]"
                                @click="navigateToItem(item)"
                                @mouseenter="selectedIndex = visibleItems.findIndex((i) => i.id === item.id)"
                            >
                                <span class="inline-flex items-center gap-2 text-sm">
                                    <component :is="iconForQuickAction(item.icon)" class="h-4 w-4 text-slate-500" />
                                    {{ item.label }}
                                </span>
                                <span class="text-xs text-slate-400">Action</span>
                            </button>
                        </div>

                        <div v-if="groupedItems.recent.length" class="mb-3">
                            <p class="px-2 py-1 text-xs font-medium uppercase tracking-wide text-slate-500">Recent</p>
                            <button
                                v-for="item in groupedItems.recent"
                                :key="item.id"
                                type="button"
                                :class="[
                                    'flex w-full items-center justify-between rounded-md px-3 py-2 text-left transition',
                                    visibleItems[selectedIndex]?.id === item.id ? 'bg-brand-50 text-brand-900' : 'hover:bg-slate-50',
                                ]"
                                @click="navigateToItem(item)"
                                @mouseenter="selectedIndex = visibleItems.findIndex((i) => i.id === item.id)"
                            >
                                <span class="text-sm">
                                    {{ item.label }}
                                    <span v-if="item.subtitle" class="ml-2 text-xs text-slate-500">{{ item.subtitle }}</span>
                                </span>
                                <span class="text-xs text-slate-400">Recent</span>
                            </button>
                        </div>

                        <div v-if="groupedItems.navigation.length">
                            <p class="px-2 py-1 text-xs font-medium uppercase tracking-wide text-slate-500">Navigation</p>
                            <button
                                v-for="item in groupedItems.navigation"
                                :key="item.id"
                                type="button"
                                :class="[
                                    'flex w-full items-center justify-between rounded-md px-3 py-2 text-left transition',
                                    visibleItems[selectedIndex]?.id === item.id ? 'bg-brand-50 text-brand-900' : 'hover:bg-slate-50',
                                ]"
                                @click="navigateToItem(item)"
                                @mouseenter="selectedIndex = visibleItems.findIndex((i) => i.id === item.id)"
                            >
                                <span class="text-sm">{{ item.label }}</span>
                                <span class="text-xs text-slate-400">Navigate</span>
                            </button>
                        </div>
                    </template>
                    <div v-else class="px-3 py-8 text-center text-sm text-slate-500">
                        No matching commands.
                    </div>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
