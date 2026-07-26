<script setup lang="ts">
import { Primitive } from 'radix-vue';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<{
    as?: string;
    variant?: 'primary' | 'secondary' | 'ghost' | 'dark';
    size?: 'sm' | 'md' | 'lg' | 'touch';
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
    /** Shows a spinner and disables the button while an action runs. */
    loading?: boolean;
}>(), {
    as: 'button',
    variant: 'primary',
    size: 'md',
    type: 'button',
    disabled: false,
    loading: false,
});

const emit = defineEmits<{
    (e: 'click', event: MouseEvent): void;
}>();

const variantClass = {
    primary: 'bg-brand-600 text-white hover:bg-brand-500',
    secondary: 'bg-white text-slate-900 border border-slate-300 hover:bg-slate-50',
    ghost: 'bg-transparent text-slate-700 hover:bg-slate-100',
    dark: 'bg-slate-900 text-white hover:bg-slate-700',
};

const sizeClass = {
    sm: 'px-3 py-1.5 text-xs',
    md: 'px-4 py-2 text-sm',
    lg: 'px-5 py-2.5 text-base',
    touch: 'min-h-12 px-5 py-3 text-base',
};
</script>

<template>
    <Primitive
        :as="as"
        :type="type"
        :disabled="disabled || loading"
        :aria-busy="loading || undefined"
        :class="cn('inline-flex items-center justify-center gap-2 rounded-md font-medium transition active:scale-[0.98] active:brightness-95 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100', variantClass[variant], sizeClass[size])"
        @click="emit('click', $event)"
    >
        <svg
            v-if="loading"
            class="h-4 w-4 shrink-0 animate-spin"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
        </svg>
        <slot />
    </Primitive>
</template>
