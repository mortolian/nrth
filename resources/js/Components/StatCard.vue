<script setup lang="ts">
defineProps<{
    title: string;
    value: string;
    hint?: string;
}>();
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ title }}</p>
        <p class="mt-1 text-2xl font-semibold text-slate-900">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
    </div>
</template>
