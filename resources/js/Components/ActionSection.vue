<script setup>
import SectionTitle from './SectionTitle.vue';
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
            <slot name="content" />
        </div>
    </section>
</template>
