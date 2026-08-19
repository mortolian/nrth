<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import InstanceSettingsShell from '@/Components/InstanceSettingsShell.vue';

defineProps<{
    mail_summary: string;
    timezone_summary: string;
    operators_summary: string;
}>();

const appVersion = computed(() => {
    const raw = (usePage().props.app_version ?? {}) as {
        current?: string;
        latest?: string | null;
        update_available?: boolean;
        url?: string | null;
        docs_url?: string | null;
    };

    return {
        current: typeof raw.current === 'string' ? raw.current : '',
        latest: typeof raw.latest === 'string' ? raw.latest : null,
        update_available: Boolean(raw.update_available),
        url: typeof raw.url === 'string' ? raw.url : null,
        docs_url: typeof raw.docs_url === 'string' && raw.docs_url !== ''
            ? raw.docs_url
            : 'https://github.com/mortolian/nrth/blob/master/docs/UPGRADE.md',
    };
});
</script>

<template>
    <InstanceSettingsShell section="overview">
        <div class="grid gap-3 sm:grid-cols-2">
            <AppCard class="sm:col-span-2">
                <h3 class="text-sm font-semibold text-slate-900">Installed version</h3>
                <p class="mt-1 text-sm tabular-nums text-slate-800">
                    v{{ appVersion.current || 'unknown' }}
                </p>
                <p v-if="appVersion.update_available" class="mt-2 text-sm text-amber-900">
                    v{{ appVersion.latest }} is available.
                    <a
                        v-if="appVersion.url"
                        :href="appVersion.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-medium underline decoration-amber-300 underline-offset-2 hover:text-amber-950"
                    >Release notes</a>
                </p>
                <p class="mt-2 text-xs text-slate-600">
                    Upgrade in place after a backup:
                    <code class="rounded bg-slate-100 px-1 py-0.5 text-[11px]">./scripts/backup &amp;&amp; ./scripts/update</code>
                    — see
                    <a
                        :href="appVersion.docs_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-medium text-brand-700 underline decoration-brand-300 underline-offset-2 hover:text-brand-800"
                    >UPGRADE.md</a>.
                </p>
            </AppCard>
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
