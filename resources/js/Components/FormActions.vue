<script setup lang="ts">
import { cn } from '@/lib/utils';

/**
 * Standard form footer for Save / Update / Cancel actions.
 * Left-aligned, primary action first, then Cancel (secondary).
 */
const props = withDefaults(defineProps<{
    /** Extra top padding / border for sticky bars or card footers. */
    bordered?: boolean;
    sticky?: boolean;
    class?: string;
}>(), {
    bordered: false,
    sticky: false,
});
</script>

<template>
    <div
        :class="cn(
            'flex flex-wrap items-center justify-start gap-3',
            sticky
                ? 'sticky bottom-0 mt-6 border-t border-slate-200 bg-white/95 px-2 py-3 backdrop-blur'
                : bordered
                    ? 'mt-6 border-t border-slate-100 pt-5'
                    : 'mt-6',
            props.class,
        )"
    >
        <slot />
    </div>
</template>
