<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export type AppTabItem = {
    id: string;
    label: string;
    href?: string;
};

const props = defineProps<{
    tabs: AppTabItem[];
    modelValue: string;
    ariaLabel?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [id: string];
}>();

const tabClass = (active: boolean) =>
    [
        'shrink-0 border-b-2 px-1 pb-3 text-sm transition whitespace-nowrap',
        active
            ? 'border-brand-600 font-semibold text-brand-800'
            : 'border-transparent font-medium text-slate-500 hover:border-slate-300 hover:text-slate-800',
    ].join(' ');
</script>

<template>
    <nav :aria-label="ariaLabel" class="-mb-px">
        <div class="flex gap-6 overflow-x-auto" role="tablist">
            <template v-for="tab in tabs" :key="tab.id">
                <Link
                    v-if="tab.href"
                    :href="tab.href"
                    role="tab"
                    :aria-selected="modelValue === tab.id"
                    :class="tabClass(modelValue === tab.id)"
                >
                    {{ tab.label }}
                </Link>
                <button
                    v-else
                    type="button"
                    role="tab"
                    :aria-selected="modelValue === tab.id"
                    :class="tabClass(modelValue === tab.id)"
                    @click="emit('update:modelValue', tab.id)"
                >
                    {{ tab.label }}
                </button>
            </template>
        </div>
    </nav>
</template>
