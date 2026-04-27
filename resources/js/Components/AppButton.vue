<script setup lang="ts">
import { Primitive } from 'radix-vue';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<{
    as?: string;
    variant?: 'primary' | 'secondary' | 'ghost' | 'dark';
    size?: 'sm' | 'md' | 'lg' | 'touch';
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
}>(), {
    as: 'button',
    variant: 'primary',
    size: 'md',
    type: 'button',
    disabled: false,
});

const emit = defineEmits<{
    (e: 'click', event: MouseEvent): void;
}>();

const variantClass = {
    primary: 'bg-brand-500 text-white hover:bg-brand-400',
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
        :disabled="disabled"
        :class="cn('inline-flex items-center justify-center rounded-md font-medium transition disabled:cursor-not-allowed disabled:opacity-50', variantClass[variant], sizeClass[size])"
        @click="emit('click', $event)"
    >
        <slot />
    </Primitive>
</template>
