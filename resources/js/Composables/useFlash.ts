import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

type FlashBag = {
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
};

/**
 * Read Inertia shared flash props.
 *
 * Do not render flash banners in pages. ToastHost (in AppLayout) displays toasts;
 * flash → toast bridging is bound once from app.js via `@/lib/flashToasts`.
 * Prefer:
 *   return back()->with('success', '…');
 *   return back()->with('error', '…');
 */
export function useFlash() {
    const page = usePage();
    const flash = computed<FlashBag>(() => page.props.flash as FlashBag ?? {});

    return {
        flash,
        success: computed(() => flash.value.success),
        error: computed(() => flash.value.error),
        warning: computed(() => flash.value.warning),
        info: computed(() => flash.value.info),
    };
}
