<script setup lang="ts">
import {
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogOverlay,
    AlertDialogPortal,
    AlertDialogRoot,
    AlertDialogTitle,
    AlertDialogTrigger,
} from 'radix-vue';

const props = withDefaults(defineProps<{
    title: string;
    description?: string;
    confirmText?: string;
    cancelText?: string;
}>(), {
    description: 'Are you sure you want to continue?',
    confirmText: 'Confirm',
    cancelText: 'Cancel',
});

const emit = defineEmits<{
    (e: 'confirm'): void;
}>();
</script>

<template>
    <AlertDialogRoot>
        <AlertDialogTrigger as-child>
            <slot name="trigger" />
        </AlertDialogTrigger>
        <AlertDialogPortal>
            <AlertDialogOverlay class="fixed inset-0 z-[80] bg-black/50" />
            <AlertDialogContent class="fixed left-1/2 top-1/2 z-[90] w-[92vw] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-lg border border-slate-200 bg-white p-5 shadow-xl">
                <AlertDialogTitle class="text-lg font-semibold text-slate-900">{{ props.title }}</AlertDialogTitle>
                <AlertDialogDescription class="mt-2 text-sm text-slate-600">{{ props.description }}</AlertDialogDescription>
                <div class="mt-5 flex justify-end gap-2">
                    <AlertDialogCancel class="rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                        {{ props.cancelText }}
                    </AlertDialogCancel>
                    <AlertDialogAction class="rounded-md bg-rose-600 px-3 py-2 text-sm text-white hover:bg-rose-700" @click="emit('confirm')">
                        {{ props.confirmText }}
                    </AlertDialogAction>
                </div>
            </AlertDialogContent>
        </AlertDialogPortal>
    </AlertDialogRoot>
</template>
