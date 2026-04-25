<script setup lang="ts">
import { computed } from 'vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const props = withDefaults(defineProps<{
    amount: number;
    currency?: string;
}>(), {
    currency: 'ZAR',
});

const formatted = computed(() => useFormatCurrency(props.amount, props.currency));
</script>

<template>
    <span>{{ formatted }}</span>
</template>
