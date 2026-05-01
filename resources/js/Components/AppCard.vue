<script setup lang="ts">
import { computed, useAttrs } from 'vue';
import type { ClassValue } from 'clsx';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const attrs = useAttrs();

const sectionClass = computed(() =>
    cn(
        'rounded-xl border border-slate-200 bg-white p-5 shadow-sm',
        attrs.class as ClassValue,
    ),
);
</script>

<template>
    <section :class="sectionClass">
        <slot />
    </section>
</template>
