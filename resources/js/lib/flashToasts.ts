import { router } from '@inertiajs/vue3';
import { useToast, type ToastVariant } from '@/Composables/useToast';

type FlashBag = {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
};

type JetstreamFlash = {
    banner?: string;
    bannerStyle?: string;
};

type PageLike = {
    props?: {
        flash?: FlashBag;
        jetstream?: { flash?: JetstreamFlash };
    };
};

const GLOBAL_KEY = '__nrthFlashToastBridge';

type BridgeState = {
    bound: boolean;
    recentKeys: Map<string, number>;
};

const DEDUPE_MS = 2500;

const getState = (): BridgeState => {
    const g = globalThis as typeof globalThis & { [GLOBAL_KEY]?: BridgeState };
    if (!g[GLOBAL_KEY]) {
        g[GLOBAL_KEY] = { bound: false, recentKeys: new Map() };
    }

    return g[GLOBAL_KEY];
};

const prune = (recentKeys: Map<string, number>, now: number) => {
    for (const [key, seenAt] of recentKeys) {
        if (now - seenAt > DEDUPE_MS) {
            recentKeys.delete(key);
        }
    }
};

const collectParts = (
    flash: FlashBag | undefined,
    jetstreamFlash: JetstreamFlash | undefined,
): Array<{ message: string; variant: ToastVariant }> => {
    const parts: Array<{ message: string; variant: ToastVariant }> = [];
    const seen = new Set<string>();

    const pushUnique = (message: string, variant: ToastVariant) => {
        const trimmed = message.trim();
        if (!trimmed) {
            return;
        }
        const key = `${variant}:${trimmed}`;
        if (seen.has(key)) {
            return;
        }
        seen.add(key);
        parts.push({ message: trimmed, variant });
    };

    if (flash?.success) pushUnique(flash.success, 'success');
    if (flash?.error) pushUnique(flash.error, 'error');
    if (flash?.warning) pushUnique(flash.warning, 'warning');
    if (flash?.info) pushUnique(flash.info, 'info');

    if (jetstreamFlash?.banner) {
        const style = jetstreamFlash.bannerStyle ?? 'success';
        const variant: ToastVariant =
            style === 'danger' ? 'error' : style === 'warning' ? 'warning' : 'success';
        pushUnique(jetstreamFlash.banner, variant);
    }

    return parts;
};

const publish = (page: PageLike | undefined) => {
    const parts = collectParts(page?.props?.flash, page?.props?.jetstream?.flash);
    if (!parts.length) {
        return;
    }

    const fingerprint = parts.map((part) => `${part.variant}:${part.message}`).join('|');
    const state = getState();
    const now = Date.now();
    prune(state.recentKeys, now);
    if (state.recentKeys.has(fingerprint)) {
        return;
    }
    state.recentKeys.set(fingerprint, now);

    const { success, error, warning, info } = useToast();
    for (const part of parts) {
        if (part.variant === 'success') success(part.message);
        else if (part.variant === 'error') error(part.message);
        else if (part.variant === 'warning') warning(part.message);
        else info(part.message);
    }
};

/**
 * Bind once for the browser tab (survives Vite HMR module re-evals).
 * Call from app bootstrap — not from Vue component mount.
 */
export function bindFlashToastBridge(initialPage?: PageLike): void {
    const state = getState();
    if (state.bound) {
        return;
    }
    state.bound = true;

    if (initialPage) {
        publish(initialPage);
    }

    router.on('success', (event) => {
        publish(event.detail.page as PageLike);
    });
}
