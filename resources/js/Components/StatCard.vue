<script setup lang="ts">
import { computed } from 'vue';
import { ArrowDownRight, ArrowRight, ArrowUpRight } from 'lucide-vue-next';

const props = withDefaults(defineProps<{
    title: string;
    value: string;
    trend?: 'up' | 'down' | 'neutral';
    trendPercent?: number | null;
    hint?: string;
    icon?: unknown;
    /** Denser padding/type for mobile strips and tight grids. */
    compact?: boolean;
}>(), {
    trend: 'neutral',
    trendPercent: null,
    hint: '',
    icon: null,
    compact: false,
});

const trendMeta = computed(() => ({
    up: { color: 'text-brand-600', bg: 'bg-brand-50', icon: ArrowUpRight, sign: '+' },
    down: { color: 'text-rose-600', bg: 'bg-rose-50', icon: ArrowDownRight, sign: '' },
    neutral: { color: 'text-slate-500', bg: 'bg-slate-100', icon: ArrowRight, sign: '' },
}[props.trend]));
</script>

<template>
    <div
        :class="[
            'flex h-full flex-col rounded-xl border border-slate-200 bg-white shadow-sm',
            compact ? 'p-3' : 'p-4',
        ]"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ title }}</p>
                <p
                    :class="[
                        'mt-1 break-words font-semibold tabular-nums text-slate-900',
                        compact ? 'text-lg leading-snug' : 'text-2xl',
                    ]"
                >
                    {{ value }}
                </p>
            </div>
            <div v-if="icon" class="shrink-0 rounded-lg bg-slate-100 p-2 text-slate-600">
                <component :is="icon" class="h-4 w-4" />
            </div>
        </div>

        <div
            v-if="!compact && (trendPercent !== null || hint)"
            class="mt-auto flex items-center gap-2 pt-2 text-xs"
        >
            <span
                v-if="trendPercent !== null"
                :class="[trendMeta.bg, trendMeta.color]"
                class="inline-flex items-center gap-1 rounded-full px-2 py-1 font-medium"
            >
                <component :is="trendMeta.icon" class="h-3.5 w-3.5" />
                {{ trendMeta.sign }}{{ trendPercent }}%
            </span>
            <span class="min-w-0 text-slate-500">{{ hint }}</span>
        </div>
    </div>
</template>
