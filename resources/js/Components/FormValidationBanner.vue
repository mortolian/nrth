<script setup lang="ts">
defineProps<{
    title?: string;
    errors: string[];
}>();
</script>

<template>
    <div
        v-if="errors.length"
        class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
        role="alert"
    >
        <p class="font-medium">{{ title ?? 'Please fix the following' }}</p>
        <ul class="mt-2 list-inside list-disc space-y-1 text-rose-800">
            <li v-for="(message, index) in errors" :key="`${index}-${message}`">{{ message }}</li>
        </ul>
    </div>
</template>
