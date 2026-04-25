<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    variant?: 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'default';
}>(), {
    variant: 'neutral',
});

const className = computed(() => ({
    success: 'bg-emerald-100 text-emerald-800',
    warning: 'bg-amber-100 text-amber-800',
    danger: 'bg-rose-100 text-rose-800',
    info: 'bg-sky-100 text-sky-800',
    neutral: 'bg-slate-100 text-slate-800',
    default: 'bg-slate-100 text-slate-800',
}[props.variant]));
</script>

<template>
    <span :class="`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${className}`">
        <slot />
    </span>
</template>
