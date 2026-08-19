<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = withDefaults(defineProps<{
    compact?: boolean;
    align?: 'start' | 'center';
}>(), {
    compact: false,
    align: 'start',
});

type AppVersionPayload = {
    current?: string;
    latest?: string | null;
    update_available?: boolean;
    url?: string | null;
};

const payload = computed((): Required<AppVersionPayload> => {
    const raw = (usePage().props.app_version ?? {}) as AppVersionPayload;
    const current = typeof raw.current === 'string' && raw.current.trim() !== '' ? raw.current.trim() : '';

    return {
        current,
        latest: typeof raw.latest === 'string' && raw.latest.trim() !== '' ? raw.latest.trim() : null,
        update_available: Boolean(raw.update_available),
        url: typeof raw.url === 'string' && raw.url.trim() !== '' ? raw.url.trim() : null,
    };
});

const label = computed(() => (payload.value.current !== '' ? `v${payload.value.current}` : ''));
const updateLabel = computed(() => (payload.value.latest ? `v${payload.value.latest} available` : 'Update available'));
</script>

<template>
    <div
        v-if="label"
        :class="[
            compact ? 'flex flex-col items-center gap-0.5' : 'space-y-0.5',
            align === 'center' ? 'text-center' : '',
        ]"
    >
        <p
            :class="compact
                ? 'text-center text-[9px] font-medium tabular-nums text-slate-500'
                : 'text-[11px] font-medium tabular-nums text-slate-500'"
        >
            {{ compact ? payload.current : label }}
        </p>
        <a
            v-if="payload.update_available && payload.url"
            :href="payload.url"
            target="_blank"
            rel="noopener noreferrer"
            :class="compact
                ? 'text-center text-[9px] font-semibold leading-tight text-amber-800 hover:underline'
                : 'block text-[11px] font-medium text-amber-800 hover:underline'"
        >
            {{ compact ? 'Update' : updateLabel }}
        </a>
        <p
            v-else-if="payload.update_available"
            :class="compact
                ? 'text-center text-[9px] font-semibold leading-tight text-amber-800'
                : 'text-[11px] font-medium text-amber-800'"
        >
            {{ compact ? 'Update' : updateLabel }}
        </p>
    </div>
</template>
