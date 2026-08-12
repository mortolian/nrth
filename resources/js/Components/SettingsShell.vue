<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppTabs from '@/Components/AppTabs.vue';
import type { AppTabItem } from '@/Components/AppTabs.vue';

const props = defineProps<{
    section: 'profile' | 'business' | 'team' | 'note-templates' | 'instance' | 'features' | 'backups';
    title?: string;
    subtitle?: string;
}>();

const page = usePage();
const teamPermissions = computed(() => {
    const perms = page.props.team_permissions;
    return Array.isArray(perms) ? (perms as string[]) : [];
});
const canTeam = (permission: string) => teamPermissions.value.includes(permission);
const canManageInstance = computed(() => Boolean(page.props.can_manage_backups));
const canAccessBackupsExports = computed(() => Boolean(page.props.can_access_backups_exports));

const sections = computed((): AppTabItem[] => {
    const tabs: AppTabItem[] = [
        { id: 'profile', label: 'Profile', href: route('profile.show') },
    ];

    if (canTeam('settings.business')) {
        tabs.push({ id: 'business', label: 'Business', href: route('settings.business') });
        tabs.push({ id: 'features', label: 'Features', href: route('settings.features') });
        tabs.push({ id: 'note-templates', label: 'Note templates', href: route('settings.note-templates.index') });
    }

    if (canTeam('settings.team')) {
        tabs.push({ id: 'team', label: 'Team members', href: route('settings.team') });
    }

    if (canAccessBackupsExports.value) {
        tabs.push({ id: 'backups', label: 'Backups & exports', href: route('backups-exports.index') });
    }

    if (canManageInstance.value) {
        tabs.push({ id: 'instance', label: 'Instance', href: route('settings.instance') });
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
        case 'features':
            return 'Optional modules for this business. Disabling hides them without deleting data.';
        case 'backups':
            return 'Tax data takeouts for your team, and whole-server backups for operators.';
        case 'team':
            return 'People who can access the currently selected business.';
        case 'note-templates':
            return 'Named markdown snippets for invoice and estimate notes.';
        case 'instance':
            return 'Install-wide settings for operators — separate from each business.';
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
