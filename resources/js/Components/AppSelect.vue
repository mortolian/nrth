<script setup lang="ts">
import { computed } from 'vue';
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
    disabled?: boolean;
}>(), {
    placeholder: 'Select...',
    disabled: false,
});

const model = defineModel<string>();
const EMPTY_SENTINEL = '__appselect_empty__';

const normalizedOptions = computed(() =>
    props.options.map((option) => ({
        ...option,
        normalizedValue: option.value === '' ? EMPTY_SENTINEL : option.value,
    }))
);

const selectModel = computed({
    get: () => (model.value === '' ? EMPTY_SENTINEL : model.value),
    set: (value: string) => {
        model.value = value === EMPTY_SENTINEL ? '' : value;
    },
});
</script>

<template>
    <SelectRoot v-model="selectModel" :disabled="props.disabled">
        <SelectTrigger
            class="inline-flex w-full items-center justify-between rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
            :class="props.disabled ? 'cursor-not-allowed opacity-60' : ''"
        >
            <SelectValue :placeholder="props.placeholder" />
        </SelectTrigger>
        <SelectPortal>
            <SelectContent class="z-50 min-w-[10rem] rounded-md border border-slate-200 bg-white p-1 shadow-sm">
                <SelectViewport>
                    <SelectItem
                        v-for="option in normalizedOptions"
                        :key="`${option.label}-${option.normalizedValue}`"
                        :value="option.normalizedValue"
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
