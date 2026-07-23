<script setup lang="ts">
import { computed } from 'vue';
import { ChevronDown } from 'lucide-vue-next';
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

const hasEmptyOption = computed(() => props.options.some((option) => option.value === ''));

const normalizedOptions = computed(() =>
    props.options.map((option) => ({
        ...option,
        normalizedValue: option.value === '' ? EMPTY_SENTINEL : option.value,
    })),
);

const selectModel = computed<string | undefined>({
    get: () => {
        if (model.value === undefined || model.value === null || model.value === '') {
            // Only use the empty sentinel when "" is a real selectable option.
            // Otherwise leave unset so the placeholder renders at full control height.
            return hasEmptyOption.value ? EMPTY_SENTINEL : undefined;
        }

        return model.value;
    },
    set: (value) => {
        if (value === undefined || value === EMPTY_SENTINEL) {
            model.value = '';
            return;
        }
        model.value = value;
    },
});
</script>

<template>
    <SelectRoot v-model="selectModel" :disabled="props.disabled">
        <SelectTrigger
            class="inline-flex h-10 w-full items-center justify-between gap-2 rounded-md border border-slate-300 bg-white px-3 text-sm leading-normal outline-none ring-slate-300 transition focus:ring-2 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:opacity-60 data-[placeholder]:text-slate-400"
            :class="props.disabled ? 'cursor-not-allowed opacity-60' : ''"
        >
            <SelectValue :placeholder="props.placeholder" class="min-w-0 flex-1 truncate text-left" />
            <ChevronDown class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
        </SelectTrigger>
        <SelectPortal>
            <SelectContent class="z-[200] min-w-[10rem] rounded-md border border-slate-200 bg-white p-1 shadow-sm">
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
