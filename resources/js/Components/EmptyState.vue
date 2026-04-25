<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
    icon?: unknown;
}>();
</script>

<template>
    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center">
        <div v-if="icon" class="mx-auto mb-3 w-fit rounded-full bg-white p-3 text-slate-500 shadow-sm">
            <component :is="icon" class="h-5 w-5" />
        </div>
        <h3 class="text-base font-semibold text-slate-900">{{ title }}</h3>
        <p v-if="description" class="mx-auto mt-1 max-w-md text-sm text-slate-500">{{ description }}</p>
        <div class="mt-4 flex justify-center">
            <slot name="action" />
        </div>
    </div>
</template>
