/**
 * Resolve the application display name from Inertia props, Vite env, or DOM meta.
 * Falls back to Laravel’s default when nothing is configured.
 */
export function readAppNameFromMeta(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return document.querySelector('meta[name="application-name"]')?.getAttribute('content')?.trim() ?? '';
}

export function resolveAppName(inertiaProps: { appName?: string }): string {
    const fromInertia = inertiaProps.appName?.trim();
    if (fromInertia) {
        return fromInertia;
    }

    const vite = import.meta.env.VITE_APP_NAME;
    if (vite !== undefined && vite !== null && String(vite).trim() !== '') {
        return String(vite).trim();
    }

    const fromMeta = readAppNameFromMeta();
    if (fromMeta) {
        return fromMeta;
    }

    return 'Laravel';
}
