<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    variant?: 'default' | 'success' | 'warning' | 'danger';
}>(), {
    variant: 'default',
});

const className = computed(() => ({
    default: 'bg-slate-100 text-slate-800',
    success: 'bg-emerald-100 text-emerald-800',
    warning: 'bg-amber-100 text-amber-800',
    danger: 'bg-rose-100 text-rose-800',
}[props.variant]));
</script>

<template>
    <span :class="`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${className}`">
        <slot />
    </span>
</template>
