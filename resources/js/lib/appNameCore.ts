/**
 * Resolve the application display name from Inertia props, Vite env, or DOM meta.
 */
export function readAppNameFromMeta(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return document.querySelector('meta[name="application-name"]')?.getAttribute('content')?.trim() ?? '';
}

export function resolveAppName(inertiaProps: { appName?: string }): string {
    return inertiaProps.appName?.trim()
        || String(import.meta.env.VITE_APP_NAME ?? '').trim()
        || readAppNameFromMeta()
        || '';
}
