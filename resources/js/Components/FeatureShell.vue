<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import AppTabs from '@/Components/AppTabs.vue';
import type { AppTabItem } from '@/Components/AppTabs.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps<{
    title: string;
    section: string;
    tabs: AppTabItem[];
    subtitle?: string;
    documentTitle?: string;
    ariaLabel?: string;
}>();
</script>

<template>
    <AppLayout
        :title="documentTitle ?? title"
        :breadcrumbs="[{ label: title }]"
    >
        <PageHeader :title="title" :subtitle="subtitle">
            <template v-if="$slots.actions" #actions>
                <slot name="actions" />
            </template>
        </PageHeader>

        <AppTabs
            class="mt-5"
            :tabs="tabs"
            :model-value="section"
            :aria-label="ariaLabel ?? `${title} sections`"
        />

        <div class="mt-6">
            <slot />
        </div>
    </AppLayout>
</template>
