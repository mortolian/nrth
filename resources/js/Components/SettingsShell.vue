<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppTabs from '@/Components/AppTabs.vue';
import type { AppTabItem } from '@/Components/AppTabs.vue';

const props = defineProps<{
    section: 'profile' | 'business' | 'team';
    title?: string;
    subtitle?: string;
}>();

const sections = computed((): AppTabItem[] => [
    { id: 'profile', label: 'Profile', href: route('profile.show') },
    { id: 'business', label: 'Business', href: route('settings.business') },
    { id: 'team', label: 'Team members', href: route('settings.team') },
]);

const activeSection = computed(() => sections.value.find((s) => s.id === props.section));

const pageTitle = computed(() => {
    if (props.title) {
        return props.title;
    }

    return activeSection.value ? `Settings · ${activeSection.value.label}` : 'Settings';
});

const headerSubtitle = computed(() => {
    if (props.subtitle) {
        return props.subtitle;
    }

    switch (props.section) {
        case 'profile':
            return 'Your account, password, two-factor authentication, and preferences.';
        case 'business':
            return 'Profile, invoicing, tax, banking, and online payments for the current business.';
        case 'team':
            return 'People who can access the currently selected business.';
        default:
            return 'Account, business, and access settings.';
    }
});
</script>

<template>
    <AppLayout
        :title="pageTitle"
        :breadcrumbs="[{ label: 'Settings' }]"
    >
        <PageHeader title="Settings" :subtitle="headerSubtitle" />

        <AppTabs
            class="mt-5"
            :tabs="sections"
            :model-value="section"
            aria-label="Settings sections"
        />

        <div class="mt-6">
            <slot />
        </div>
    </AppLayout>
</template>
