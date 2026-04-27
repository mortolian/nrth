<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Pause, Play, RotateCcw } from 'lucide-vue-next';

const STORAGE_KEY = 'nrth_time_tracker_v1';

type Stored = { accumulatedMs: number; startedAt: number | null; label: string };

const label = ref('');
const accumulatedMs = ref(0);
const startedAt = ref<number | null>(null);
const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | null = null;

const load = () => {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return;
        const data = JSON.parse(raw) as Stored;
        accumulatedMs.value = typeof data.accumulatedMs === 'number' ? Math.max(0, data.accumulatedMs) : 0;
        startedAt.value = typeof data.startedAt === 'number' ? data.startedAt : null;
        label.value = typeof data.label === 'string' ? data.label : '';
    } catch {
        /* ignore */
    }
};

const persist = () => {
    const data: Stored = {
        accumulatedMs: accumulatedMs.value,
        startedAt: startedAt.value,
        label: label.value,
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
};

const isRunning = computed(() => startedAt.value !== null);

const elapsedMs = computed(() => {
    const running = startedAt.value !== null ? now.value - startedAt.value : 0;
    return accumulatedMs.value + Math.max(0, running);
});

const elapsedLabel = computed(() => {
    const totalSec = Math.floor(elapsedMs.value / 1000);
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    if (h > 0) {
        return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }
    return `${m}:${String(s).padStart(2, '0')}`;
});

const start = () => {
    if (startedAt.value !== null) return;
    startedAt.value = Date.now();
    persist();
};

const stop = () => {
    if (startedAt.value === null) return;
    accumulatedMs.value += Math.max(0, now.value - startedAt.value);
    startedAt.value = null;
    persist();
};

const reset = () => {
    accumulatedMs.value = 0;
    startedAt.value = null;
    persist();
};

const onLabelInput = () => {
    persist();
};

onMounted(() => {
    load();
    tick = setInterval(() => {
        now.value = Date.now();
    }, 500);
});

onBeforeUnmount(() => {
    if (tick) clearInterval(tick);
});
</script>

<template>
    <AppLayout
        title="Time tracking"
        :breadcrumbs="[
            { label: 'Time tracking' },
        ]"
    >
        <PageHeader title="Time tracking" subtitle="Simple stopwatch for billable work — stored on this device only" />

        <div class="mx-auto mt-6 max-w-md space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-6 py-10 text-center shadow-sm">
                <p class="font-mono text-5xl font-semibold tabular-nums tracking-tight text-slate-900 md:text-6xl">
                    {{ elapsedLabel }}
                </p>
                <p v-if="isRunning" class="mt-3 text-sm text-brand-700">
                    Timer running
                </p>
                <p v-else class="mt-3 text-sm text-slate-500">
                    Paused
                </p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-500">What are you working on? (optional)</label>
                <input
                    v-model="label"
                    type="text"
                    class="min-h-12 w-full rounded-md border border-slate-300 px-3 py-3 text-base outline-none ring-slate-300 focus:ring-2"
                    placeholder="e.g. Acme — website"
                    @blur="onLabelInput"
                    @input="onLabelInput"
                >
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:justify-center">
                <AppButton
                    v-if="!isRunning"
                    size="touch"
                    variant="primary"
                    class="w-full sm:w-auto sm:min-w-[10rem]"
                    @click="start"
                >
                    <Play class="mr-2 h-5 w-5" />
                    Start
                </AppButton>
                <AppButton
                    v-else
                    size="touch"
                    variant="secondary"
                    class="w-full sm:w-auto sm:min-w-[10rem]"
                    @click="stop"
                >
                    <Pause class="mr-2 h-5 w-5" />
                    Stop
                </AppButton>
                <AppButton
                    size="touch"
                    variant="ghost"
                    class="w-full sm:w-auto sm:min-w-[10rem]"
                    :disabled="elapsedMs === 0"
                    @click="reset"
                >
                    <RotateCcw class="mr-2 h-5 w-5" />
                    Reset
                </AppButton>
            </div>

            <p class="text-center text-xs text-slate-500">
                Time is not synced to the server. Use this for on-the-spot tracking; log time to invoices or expenses separately if needed.
            </p>
        </div>
    </AppLayout>
</template>
