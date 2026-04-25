<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    value: string | Date | null;
    relative?: boolean;
}>();

const relativeLabel = (date: Date) => {
    const now = new Date();
    const diffMs = date.getTime() - now.getTime();
    const diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24));
    if (diffDays === 0) return 'today';
    if (diffDays === -1) return '1 day ago';
    if (diffDays === 1) return 'in 1 day';
    if (diffDays < 0) return `${Math.abs(diffDays)} days ago`;
    return `in ${diffDays} days`;
};

const formatted = computed(() => {
    if (!props.value) return '—';
    const date = props.value instanceof Date ? props.value : new Date(props.value);
    if (props.relative) return relativeLabel(date);
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
