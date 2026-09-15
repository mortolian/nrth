<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ChevronDown } from 'lucide-vue-next';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxTrigger,
    ComboboxViewport,
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
    /** Compact control for dense tables (e.g. invoice lines). */
    size?: 'md' | 'sm';
    /** Closed control looks like a select; search sits at the top of the list. */
    searchable?: boolean;
    searchPlaceholder?: string;
}>(), {
    placeholder: 'Select...',
    disabled: false,
    size: 'md',
    searchable: false,
    searchPlaceholder: 'Search...',
});

const model = defineModel<string>();
const EMPTY_SENTINEL = '__appselect_empty__';
const searchTerm = ref('');

/**
 * Native <dialog showModal()> uses the browser top layer. Portaling to body
 * always paints behind it no matter the z-index — target the open dialog instead.
 */
const portalTo = ref<HTMLElement | string>('body');

function syncPortalTarget(): void {
    const openDialog = document.querySelector('dialog[open]');
    portalTo.value = openDialog instanceof HTMLElement ? openDialog : 'body';
}

onMounted(() => {
    syncPortalTarget();
});

const onOpenChange = (open: boolean) => {
    if (open) {
        syncPortalTarget();
        searchTerm.value = '';
    }
};

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

const selectedLabel = computed(() => {
    const current = selectModel.value;
    if (current === undefined) {
        return '';
    }

    return normalizedOptions.value.find((option) => option.normalizedValue === current)?.label ?? '';
});

const filterByLabel = (values: string[], term: string): string[] => {
    const query = term.trim().toLowerCase();
    if (query === '') {
        return values;
    }

    const labels = new Map(
        normalizedOptions.value.map((option) => [option.normalizedValue, option.label]),
    );

    return values.filter((value) => (labels.get(value) ?? '').toLowerCase().includes(query));
};

const triggerClass = computed(() =>
    props.size === 'sm'
        ? 'inline-flex h-8 w-full items-center justify-between gap-1.5 rounded-md border border-slate-300 bg-white px-2 text-xs leading-normal text-slate-900 outline-none ring-slate-300 transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:opacity-60 data-[placeholder]:text-slate-400'
        : 'inline-flex h-10 w-full items-center justify-between gap-2 rounded-md border border-slate-300 bg-white px-3 text-sm leading-normal text-slate-900 outline-none ring-slate-300 transition focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:opacity-60 data-[placeholder]:text-slate-400',
);
</script>

<template>
    <ComboboxRoot
        v-if="props.searchable"
        v-model="selectModel"
        v-model:search-term="searchTerm"
        class="block w-full"
        :disabled="props.disabled"
        :display-value="() => ''"
        :filter-function="filterByLabel"
        @update:open="onOpenChange"
    >
        <ComboboxAnchor class="block w-full">
            <ComboboxTrigger
                :class="[triggerClass, props.disabled ? 'cursor-not-allowed opacity-60' : '']"
                type="button"
                :disabled="props.disabled"
            >
                <span
                    class="min-w-0 flex-1 truncate text-left"
                    :class="selectedLabel ? 'text-slate-900' : 'text-slate-400'"
                >
                    {{ selectedLabel || props.placeholder }}
                </span>
                <ChevronDown class="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden="true" />
            </ComboboxTrigger>
        </ComboboxAnchor>
        <ComboboxPortal :to="portalTo">
            <ComboboxContent
                position="popper"
                class="z-[200] mt-1 flex w-[var(--radix-combobox-trigger-width)] min-w-[10rem] flex-col overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm"
            >
                <div class="shrink-0 border-b border-slate-100 p-1.5">
                    <ComboboxInput
                        auto-focus
                        :placeholder="props.searchPlaceholder"
                        autocomplete="off"
                        class="h-8 w-full rounded-md border border-slate-200 bg-slate-50 px-2 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-500/25"
                    />
                </div>
                <ComboboxViewport class="max-h-60 overflow-y-auto p-1">
                    <ComboboxEmpty class="px-3 py-2 text-sm text-slate-500">
                        No matches
                    </ComboboxEmpty>
                    <ComboboxItem
                        v-for="option in normalizedOptions"
                        :key="`${option.label}-${option.normalizedValue}`"
                        :value="option.normalizedValue"
                        class="relative flex cursor-pointer select-none items-center rounded px-8 py-2 text-sm outline-none data-[highlighted]:bg-slate-100"
                    >
                        <ComboboxItemIndicator class="absolute left-2">✓</ComboboxItemIndicator>
                        {{ option.label }}
                    </ComboboxItem>
                </ComboboxViewport>
            </ComboboxContent>
        </ComboboxPortal>
    </ComboboxRoot>

    <SelectRoot
        v-else
        v-model="selectModel"
        class="block w-full"
        :disabled="props.disabled"
        @update:open="onOpenChange"
    >
        <SelectTrigger
            :class="[triggerClass, props.disabled ? 'cursor-not-allowed opacity-60' : '']"
        >
            <SelectValue :placeholder="props.placeholder" class="min-w-0 flex-1 truncate text-left" />
            <ChevronDown class="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden="true" />
        </SelectTrigger>
        <SelectPortal :to="portalTo">
            <SelectContent class="z-[200] max-h-72 w-[var(--radix-select-trigger-width)] min-w-[10rem] overflow-y-auto rounded-md border border-slate-200 bg-white p-1 shadow-sm">
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
