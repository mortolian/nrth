<script setup lang="ts">
import { computed, useAttrs } from 'vue';
import type { ClassValue } from 'clsx';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const attrs = useAttrs();

const sectionClass = computed(() =>
    cn(
        'rounded-xl border border-slate-300/70 bg-white p-5 shadow-sm',
        attrs.class as ClassValue,
    ),
);

const forwardedAttrs = computed(() => {
    const { class: _class, ...rest } = attrs;

    return rest;
});
</script>

<template>
    <section :class="sectionClass" v-bind="forwardedAttrs">
        <slot />
    </section>
</template>
