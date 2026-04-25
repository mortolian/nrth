<script setup lang="ts">
import {
    SelectContent,
    SelectItem,
    SelectItemIndicator,
    SelectItemText,
    SelectPortal,
    SelectRoot,
    SelectTrigger,
    SelectValue,
    SelectViewport,
} from 'radix-vue';

type Option = { label: string; value: string };

const props = withDefaults(defineProps<{
    options: Option[];
    placeholder?: string;
}>(), {
    placeholder: 'Select...',
});

const model = defineModel<string>();
</script>

<template>
    <SelectRoot v-model="model">
        <SelectTrigger class="inline-flex w-full items-center justify-between rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
            <SelectValue :placeholder="props.placeholder" />
        </SelectTrigger>
        <SelectPortal>
            <SelectContent class="z-50 min-w-[10rem] rounded-md border border-slate-200 bg-white p-1 shadow-sm">
                <SelectViewport>
                    <SelectItem
                        v-for="option in props.options"
                        :key="option.value"
                        :value="option.value"
                        class="relative flex cursor-pointer select-none items-center rounded px-8 py-2 text-sm outline-none hover:bg-slate-100"
                    >
                        <SelectItemIndicator class="absolute left-2">✓</SelectItemIndicator>
                        <SelectItemText>{{ option.label }}</SelectItemText>
                    </SelectItem>
                </SelectViewport>
            </SelectContent>
        </SelectPortal>
    </SelectRoot>
</template>
