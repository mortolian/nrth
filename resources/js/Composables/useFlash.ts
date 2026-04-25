import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

type FlashBag = {
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
};

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
