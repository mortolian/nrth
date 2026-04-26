<script setup>
import { computed, useSlots } from 'vue';
import SectionTitle from './SectionTitle.vue';

defineEmits(['submitted']);

const hasActions = computed(() => !! useSlots().actions);
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm md:grid md:grid-cols-3 md:gap-8">
        <SectionTitle>
            <template #title>
                <slot name="title" />
            </template>
            <template #description>
                <slot name="description" />
            </template>
        </SectionTitle>

        <div class="mt-6 md:mt-0 md:col-span-2">
            <form @submit.prevent="$emit('submitted')">
                <div class="grid grid-cols-6 gap-6">
                    <slot name="form" />
                </div>

                <div
                    v-if="hasActions"
                    class="mt-6 flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-5"
                >
                    <slot name="actions" />
                </div>
            </form>
        </div>
    </section>
</template>
