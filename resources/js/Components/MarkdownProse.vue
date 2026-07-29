<script setup lang="ts">
defineProps<{
    html?: string | null;
    /** Optional plain-text fallback when html is empty but text exists. */
    text?: string | null;
}>();
</script>

<template>
    <div
        v-if="html"
        class="prose prose-sm max-w-none text-slate-700 prose-p:my-2 prose-ul:my-2 prose-ol:my-2 prose-headings:my-2 prose-a:text-brand-700"
        v-html="html"
    />
    <div v-else-if="text" class="whitespace-pre-wrap text-sm text-slate-700">{{ text }}</div>
</template>
