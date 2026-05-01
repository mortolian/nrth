<script setup lang="ts">
import { MoreVertical } from 'lucide-vue-next';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuTrigger,
} from 'radix-vue';

export type RowActionItem = { id: string; label: string };

defineProps<{
    actions: RowActionItem[];
    /** Accessible name for the trigger (e.g. invoice number). */
    ariaLabel?: string;
}>();

const emit = defineEmits<{
    select: [actionId: string];
}>();
</script>

<template>
    <DropdownMenuRoot>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1"
                :aria-label="ariaLabel ?? 'Invoice actions'"
                @click.stop
            >
                <MoreVertical class="h-4 w-4" aria-hidden="true" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <DropdownMenuContent
                class="z-[200] min-w-[11rem] rounded-md border border-slate-200 bg-white p-1 shadow-md outline-none"
                :side-offset="4"
                align="end"
            >
                <DropdownMenuItem
                    v-for="action in actions"
                    :key="action.id"
                    class="flex cursor-pointer select-none rounded px-3 py-2 text-sm outline-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
                    :class="
                        action.id === 'delete'
                            ? 'text-red-600 data-[highlighted]:bg-red-50'
                            : action.id === 'undo_invoice_payment'
                              ? 'text-amber-800 data-[highlighted]:bg-amber-50'
                              : 'text-slate-700 data-[highlighted]:bg-slate-100'
                    "
                    @select="emit('select', action.id)"
                >
                    {{ action.label }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
