<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import InstanceSettingsShell from '@/Components/InstanceSettingsShell.vue';

defineProps<{
    mail_summary: string;
    operators_summary: string;
}>();
</script>

<template>
    <InstanceSettingsShell section="overview">
        <div class="grid gap-3 sm:grid-cols-2">
            <Link :href="route('settings.instance.mail')" class="block">
                <AppCard class="h-full cursor-pointer transition hover:border-slate-300 hover:bg-slate-50">
                    <h3 class="text-sm font-semibold text-slate-900">Outbound email (SMTP)</h3>
                    <p class="mt-1 text-xs text-slate-600">{{ mail_summary }}</p>
                </AppCard>
            </Link>
            <Link :href="route('settings.instance.operators')" class="block">
                <AppCard class="h-full cursor-pointer transition hover:border-slate-300 hover:bg-slate-50">
                    <h3 class="text-sm font-semibold text-slate-900">Operators</h3>
                    <p class="mt-1 text-xs text-slate-600">{{ operators_summary }}</p>
                </AppCard>
            </Link>
        </div>

        <p class="mt-6 text-sm text-slate-600">
            Whole-server backups stay under
            <Link
                :href="route('backups-exports.index', { section: 'backup' })"
                class="font-medium text-brand-700 hover:underline"
            >
                Backups &amp; exports
            </Link>
            (destinations, retention, and restore).
        </p>
    </InstanceSettingsShell>
</template>
