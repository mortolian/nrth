import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { resolveAppName } from '@/lib/appNameCore';

export { readAppNameFromMeta, resolveAppName } from '@/lib/appNameCore';

export function useAppDisplayName() {
    const page = usePage();

    return computed(() => resolveAppName(page.props as { appName?: string }));
}
