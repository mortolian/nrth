<script setup lang="ts">
defineProps<{
    title: string;
    subtitle?: string;
    /** Drop bottom margin when tabs (or other chrome) sit directly under the header. */
    flush?: boolean;
}>();
</script>

<template>
    <div
        :class="[
            'flex flex-col justify-between gap-3 sm:flex-row sm:items-center',
            flush ? 'mb-0' : 'mb-6',
        ]"
    >
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="truncate text-2xl font-semibold tracking-tight text-slate-900" :title="title">{{ title }}</h1>
                <slot name="status" />
            </div>
            <p v-if="subtitle" class="mt-1 text-sm text-slate-600">{{ subtitle }}</p>
        </div>
        <div class="flex w-full flex-wrap items-center justify-end gap-2 sm:w-auto sm:shrink-0">
            <slot name="actions" />
        </div>
    </div>
</template>
