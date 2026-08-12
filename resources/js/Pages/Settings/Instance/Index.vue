<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import InstanceSettingsShell from '@/Components/InstanceSettingsShell.vue';

defineProps<{
    mail_summary: string;
    timezone_summary: string;
    operators_summary: string;
}>();
</script>

<template>
    <InstanceSettingsShell section="overview">
        <div class="grid gap-3 sm:grid-cols-2">
            <Link :href="route('settings.instance.timezone')" class="block">
                <AppCard class="h-full cursor-pointer transition hover:border-slate-300 hover:bg-slate-50">
                    <h3 class="text-sm font-semibold text-slate-900">Timezone</h3>
                    <p class="mt-1 text-xs text-slate-600">{{ timezone_summary }}</p>
                </AppCard>
            </Link>
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
    </InstanceSettingsShell>
</template>
