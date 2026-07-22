<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { useToast, type ToastVariant } from '@/Composables/useToast';

const { toasts, dismiss } = useToast();

const variantClass: Record<ToastVariant, string> = {
    success: 'border-brand-200 bg-brand-50 text-brand-900',
    error: 'border-rose-200 bg-rose-50 text-rose-900',
    warning: 'border-amber-200 bg-amber-50 text-amber-950',
    info: 'border-slate-200 bg-white text-slate-900',
};

const accentClass: Record<ToastVariant, string> = {
    success: 'bg-brand-500',
    error: 'bg-rose-500',
    warning: 'bg-amber-500',
    info: 'bg-slate-400',
};
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 top-0 z-[200] flex flex-col items-end gap-2 p-3 sm:p-4"
        aria-live="polite"
        aria-relevant="additions"
    >
        <div
            v-for="toast in toasts"
            :key="toast.id"
            class="pointer-events-auto flex w-full max-w-sm overflow-hidden rounded-lg border shadow-lg sm:max-w-md"
            :class="variantClass[toast.variant]"
            role="status"
        >
            <span class="w-1 shrink-0" :class="accentClass[toast.variant]" aria-hidden="true" />
            <p class="flex-1 px-3 py-2.5 text-sm font-medium leading-snug">
                {{ toast.message }}
            </p>
            <button
                type="button"
                class="shrink-0 px-2.5 text-current/60 transition hover:bg-black/5 hover:text-current"
                :aria-label="'Dismiss notification'"
                @click="dismiss(toast.id)"
            >
                <X class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>
