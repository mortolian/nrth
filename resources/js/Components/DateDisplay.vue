<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    value: string | Date | null;
}>();

const formatted = computed(() => {
    if (!props.value) return '—';
    const date = props.value instanceof Date ? props.value : new Date(props.value);
    return new Intl.DateTimeFormat('en-ZA', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(date);
});
</script>

<template>
    <span>{{ formatted }}</span>
</template>
