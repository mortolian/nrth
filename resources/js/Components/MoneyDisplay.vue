<script setup lang="ts">
import { computed } from 'vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const props = withDefaults(defineProps<{
    amount: number;
    currency?: string;
    size?: 'sm' | 'md' | 'lg';
}>(), {
    currency: 'ZAR',
    size: 'md',
});

const formatted = computed(() => useFormatCurrency(props.amount, props.currency));
const isNegative = computed(() => props.amount < 0);
const sizeClass = computed(() => ({
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-xl font-semibold',
}[props.size]));
</script>

<template>
    <span :class="[sizeClass, isNegative ? 'text-rose-600' : 'text-slate-900']">{{ formatted }}</span>
</template>
