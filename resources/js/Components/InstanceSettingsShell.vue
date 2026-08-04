<script setup lang="ts">
import { computed } from 'vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import AppTabs from '@/Components/AppTabs.vue';
import type { AppTabItem } from '@/Components/AppTabs.vue';

const props = defineProps<{
    section: 'overview' | 'mail' | 'operators';
    title?: string;
    subtitle?: string;
}>();

const tabs = computed((): AppTabItem[] => [
    { id: 'overview', label: 'Overview', href: route('settings.instance') },
    { id: 'mail', label: 'Outbound email', href: route('settings.instance.mail') },
    { id: 'operators', label: 'Operators', href: route('settings.instance.operators') },
]);

const headerSubtitle = computed(() => {
    if (props.subtitle) {
        return props.subtitle;
    }

    switch (props.section) {
        case 'mail':
            return 'SMTP for invitations, password resets, and invoices across this install.';
        case 'operators':
            return 'Who can manage instance settings and whole-server backups.';
        default:
            return 'Install-wide settings for operators — separate from each business.';
    }
});
</script>

<template>
    <SettingsShell
        section="instance"
        :title="title ?? 'Settings · Instance'"
        :subtitle="headerSubtitle"
    >
        <AppTabs
            :tabs="tabs"
            :model-value="section"
            aria-label="Instance settings sections"
        />

        <div class="mt-6">
            <slot />
        </div>
    </SettingsShell>
</template>
