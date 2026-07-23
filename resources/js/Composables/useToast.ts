import { computed, reactive } from 'vue';

export type ToastVariant = 'success' | 'error' | 'warning' | 'info';

export type ToastItem = {
    id: number;
    message: string;
    variant: ToastVariant;
};

const state = reactive({
    toasts: [] as ToastItem[],
    nextId: 1,
});

const DEFAULT_DURATION_MS = 4500;
const DEDUPE_MS = 2500;
let lastPushKey = '';
let lastPushAt = 0;

export function useToast() {
    const dismiss = (id: number) => {
        state.toasts = state.toasts.filter((toast) => toast.id !== id);
    };

    const push = (message: string, variant: ToastVariant = 'info', durationMs = DEFAULT_DURATION_MS) => {
        const text = message.trim();
        if (!text) {
            return;
        }

        const key = `${variant}:${text}`;
        const now = Date.now();
        if (key === lastPushKey && now - lastPushAt < DEDUPE_MS) {
            return;
        }
        lastPushKey = key;
        lastPushAt = now;

        const id = state.nextId++;
        state.toasts.push({ id, message: text, variant });

        if (durationMs > 0 && typeof window !== 'undefined') {
            window.setTimeout(() => dismiss(id), durationMs);
        }

        return id;
    };

    return {
        toasts: computed(() => state.toasts),
        push,
        success: (message: string, durationMs?: number) => push(message, 'success', durationMs),
        error: (message: string, durationMs?: number) => push(message, 'error', durationMs),
        warning: (message: string, durationMs?: number) => push(message, 'warning', durationMs),
        info: (message: string, durationMs?: number) => push(message, 'info', durationMs),
        dismiss,
        clear: () => {
            state.toasts = [];
        },
    };
}
