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
        <div class="border-b border-canvas-200">
            <PageHeader :title="title" :subtitle="subtitle" flush>
                <template v-if="$slots.actions" #actions>
                    <slot name="actions" />
                </template>
            </PageHeader>

            <div class="mt-6">
                <AppTabs
                    :tabs="tabs"
                    :model-value="section"
                    :aria-label="ariaLabel ?? `${title} sections`"
                />
            </div>
        </div>

        <div class="mt-6">
            <slot />
        </div>
    </AppLayout>
</template>
