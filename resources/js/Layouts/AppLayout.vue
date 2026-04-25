<script setup lang="ts">
import AppLayout from '@/Components/layout/AppLayout.vue';

defineProps<{
    title?: string;
    breadcrumbs?: Array<{ label: string; href?: string }>;
}>();
</script>

<template>
    <AppLayout :title="title" :breadcrumbs="breadcrumbs">
        <template #header>
            <slot name="header" />
        </template>
        <slot />
    </AppLayout>
</template>
