<script setup>
import { computed, useSlots } from 'vue';

defineEmits(['submitted']);

const hasActions = computed(() => !!useSlots().actions);
</script>

<template>
    <section class="rounded-xl border border-slate-300/70 bg-slate-50/80 p-4 md:p-5">
        <h4 class="text-sm font-semibold text-slate-900">
            <slot name="title" />
        </h4>
        <p class="mt-0.5 text-xs text-slate-600">
            <slot name="description" />
        </p>

        <form class="mt-4" @submit.prevent="$emit('submitted')">
            <div class="max-w-2xl space-y-4">
                <slot name="form" />
            </div>

            <div
                v-if="hasActions"
                class="mt-6 flex flex-wrap items-center justify-start gap-3 pt-4"
            >
                <slot name="actions" />
            </div>
        </form>
    </section>
</template>
