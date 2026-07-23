<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';

const WARN_SECONDS = 60;
const ACTIVITY_THROTTLE_MS = 1000;

const page = usePage();
const timeoutMinutes = computed(() => Number(page.props.session_idle_timeout_minutes ?? 0));

const warningOpen = ref(false);
const secondsRemaining = ref(0);
const loggingOut = ref(false);

let lastActivityAt = Date.now();
let lastThrottleAt = 0;
let tickTimer: ReturnType<typeof setInterval> | null = null;

const timeoutMs = computed(() => Math.max(0, timeoutMinutes.value) * 60 * 1000);

const markActivity = () => {
    if (timeoutMinutes.value <= 0 || loggingOut.value) return;
    const now = Date.now();
    if (now - lastThrottleAt < ACTIVITY_THROTTLE_MS) return;
    lastThrottleAt = now;
    lastActivityAt = now;
    if (warningOpen.value) {
        warningOpen.value = false;
    }
};

const staySignedIn = () => {
    markActivity();
    lastThrottleAt = 0;
    lastActivityAt = Date.now();
    warningOpen.value = false;
    router.reload({
        only: ['session_idle_timeout_minutes'],
        preserveScroll: true,
        preserveState: true,
    });
};

const signOut = () => {
    if (loggingOut.value) return;
    loggingOut.value = true;
    warningOpen.value = false;
    router.post(route('logout'));
};

const tick = () => {
    if (timeoutMinutes.value <= 0 || loggingOut.value) {
        warningOpen.value = false;
        return;
    }

    const elapsed = Date.now() - lastActivityAt;
    const remainingMs = timeoutMs.value - elapsed;
    const remainingSec = Math.ceil(remainingMs / 1000);
    secondsRemaining.value = Math.max(0, remainingSec);

    if (remainingMs <= 0) {
        signOut();
        return;
    }

    warningOpen.value = remainingSec <= WARN_SECONDS;
};

const activityEvents = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'] as const;

const bindActivity = () => {
    activityEvents.forEach((event) => window.addEventListener(event, markActivity, { passive: true }));
};

const unbindActivity = () => {
    activityEvents.forEach((event) => window.removeEventListener(event, markActivity));
};

const startWatcher = () => {
    stopWatcher();
    if (timeoutMinutes.value <= 0) return;
    lastActivityAt = Date.now();
    lastThrottleAt = 0;
    warningOpen.value = false;
    bindActivity();
    tickTimer = setInterval(tick, 1000);
    tick();
};

const stopWatcher = () => {
    unbindActivity();
    if (tickTimer) {
        clearInterval(tickTimer);
        tickTimer = null;
    }
    warningOpen.value = false;
};

watch(timeoutMinutes, () => {
    loggingOut.value = false;
    startWatcher();
});

onMounted(() => startWatcher());
onBeforeUnmount(() => stopWatcher());
</script>

<template>
    <Teleport to="body">
        <div
            v-if="warningOpen"
            class="fixed inset-0 z-[90] flex items-center justify-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="idle-timeout-title"
        >
            <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-xl">
                <h2 id="idle-timeout-title" class="text-lg font-semibold text-slate-900">
                    Still there?
                </h2>
                <p class="mt-2 text-sm text-slate-600">
                    You’ll be signed out in
                    <span class="font-semibold text-slate-900">{{ secondsRemaining }}</span>
                    {{ secondsRemaining === 1 ? 'second' : 'seconds' }} due to inactivity.
                </p>
                <div class="mt-6 flex flex-wrap justify-end gap-2">
                    <AppButton variant="secondary" :disabled="loggingOut" @click="signOut">
                        Sign out
                    </AppButton>
                    <AppButton variant="primary" :disabled="loggingOut" @click="staySignedIn">
                        Stay signed in
                    </AppButton>
                </div>
            </div>
        </div>
    </Teleport>
</template>
