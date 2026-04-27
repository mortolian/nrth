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
}>(), {
    trend: 'neutral',
    trendPercent: null,
    hint: '',
    icon: null,
});

const trendMeta = computed(() => ({
    up: { color: 'text-brand-600', bg: 'bg-brand-50', icon: ArrowUpRight, sign: '+' },
    down: { color: 'text-rose-600', bg: 'bg-rose-50', icon: ArrowDownRight, sign: '' },
    neutral: { color: 'text-slate-500', bg: 'bg-slate-100', icon: ArrowRight, sign: '' },
}[props.trend]));
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ title }}</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ value }}</p>
            </div>
            <div v-if="icon" class="rounded-lg bg-slate-100 p-2 text-slate-600">
                <component :is="icon" class="h-4 w-4" />
            </div>
        </div>

        <div v-if="trendPercent !== null || hint" class="mt-2 flex items-center gap-2 text-xs">
            <span
                v-if="trendPercent !== null"
                :class="[trendMeta.bg, trendMeta.color]"
                class="inline-flex items-center gap-1 rounded-full px-2 py-1 font-medium"
            >
                <component :is="trendMeta.icon" class="h-3.5 w-3.5" />
                {{ trendMeta.sign }}{{ trendPercent }}%
            </span>
            <span class="text-slate-500">{{ hint }}</span>
        </div>
    </div>
</template>
