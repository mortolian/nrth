import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

export type InstanceClockParts = {
    date: string;
    time: string;
    timezone: string;
    timezoneLabel: string;
};

function formatParts(now: Date, timeZone: string): InstanceClockParts {
    const date = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(now);

    const time = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).format(now);

    const tzPart = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        timeZoneName: 'short',
    })
        .formatToParts(now)
        .find((part) => part.type === 'timeZoneName')
        ?.value;

    return {
        date,
        time,
        timezone: timeZone,
        timezoneLabel: tzPart && tzPart !== timeZone ? tzPart : timeZone,
    };
}

/**
 * Live clock in the effective timezone (current business, else instance default).
 */
export function useInstanceClock(intervalMs = 1000) {
    const page = usePage();
    const now = ref(new Date());
    let timer: ReturnType<typeof setInterval> | null = null;

    const timeZone = computed(() => {
        const tz = page.props.app_timezone;
        return typeof tz === 'string' && tz.trim() !== '' ? tz : 'UTC';
    });

    const parts = computed(() => formatParts(now.value, timeZone.value));

    onMounted(() => {
        now.value = new Date();
        timer = setInterval(() => {
            now.value = new Date();
        }, intervalMs);
    });

    onBeforeUnmount(() => {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    });

    return { parts, timeZone };
}
