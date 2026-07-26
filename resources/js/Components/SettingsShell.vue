<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppTabs from '@/Components/AppTabs.vue';
import type { AppTabItem } from '@/Components/AppTabs.vue';

const props = defineProps<{
    section: 'profile' | 'business' | 'team';
    title?: string;
    subtitle?: string;
}>();

const page = usePage();
const teamPermissions = computed(() => {
    const perms = page.props.team_permissions;
    return Array.isArray(perms) ? (perms as string[]) : [];
});
const canTeam = (permission: string) => teamPermissions.value.includes(permission);

const sections = computed((): AppTabItem[] => {
    const tabs: AppTabItem[] = [
        { id: 'profile', label: 'Profile', href: route('profile.show') },
    ];

    if (canTeam('settings.business')) {
        tabs.push({ id: 'business', label: 'Business', href: route('settings.business') });
    }

    if (canTeam('settings.team')) {
        tabs.push({ id: 'team', label: 'Team members', href: route('settings.team') });
    }

    return tabs;
});

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
            return undefined;
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
        <div class="border-b border-canvas-200">
            <PageHeader title="Settings" :subtitle="headerSubtitle" flush />

            <div class="mt-6">
                <AppTabs
                    :tabs="sections"
                    :model-value="section"
                    aria-label="Settings sections"
                />
            </div>
        </div>

        <div class="mt-6">
            <slot />
        </div>
    </AppLayout>
</template>
