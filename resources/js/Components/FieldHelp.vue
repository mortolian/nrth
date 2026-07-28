<script setup lang="ts">
import { CircleHelp } from 'lucide-vue-next';

defineProps<{
    label: string;
    text: string;
}>();
</script>

<template>
    <div class="mb-1 flex items-center gap-1.5">
        <span class="text-xs font-medium text-slate-500">{{ label }}</span>
        <span class="group relative inline-flex">
            <button
                type="button"
                class="inline-flex rounded text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                :aria-label="`Help: ${label}`"
            >
                <CircleHelp class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <span
                role="tooltip"
                class="pointer-events-none absolute left-0 top-full z-50 mt-2 w-72 rounded-md bg-slate-900 px-3 py-2 text-xs leading-relaxed text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100 group-focus-within:opacity-100"
            >
                {{ text }}
            </span>
        </span>
    </div>
</template>
