<script setup lang="ts">
import { cn } from '@/lib/utils';

const model = defineModel<string | number | null>();

defineProps<{
    type?: string;
    placeholder?: string;
    disabled?: boolean;
}>();
</script>

<template>
    <input
        v-model="model"
        :type="type ?? 'text'"
        :placeholder="placeholder"
        :disabled="disabled"
        :class="cn('w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none ring-slate-300 transition focus:ring-2 disabled:cursor-not-allowed disabled:bg-slate-100')"
    >
</template>
