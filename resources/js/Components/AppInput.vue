<script setup lang="ts">
import { computed, useAttrs } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const model = defineModel<string | number | null>();

const props = defineProps<{
    type?: string;
    placeholder?: string;
    disabled?: boolean;
    /** e.g. decimal for mobile numeric keyboards */
    inputmode?: 'none' | 'text' | 'tel' | 'url' | 'email' | 'numeric' | 'decimal' | 'search';
}>();

const attrs = useAttrs();
const mergedClass = computed(() =>
    cn(
        'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none ring-slate-300 transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25 disabled:cursor-not-allowed disabled:bg-slate-100',
        attrs.class as string | string[] | Record<string, boolean> | undefined,
    ),
);
</script>

<template>
    <input
        v-bind="attrs"
        v-model="model"
        :type="props.type ?? 'text'"
        :inputmode="props.inputmode"
        :placeholder="props.placeholder"
        :disabled="props.disabled"
        :class="mergedClass"
    >
</template>
