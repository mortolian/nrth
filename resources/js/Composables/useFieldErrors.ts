import { computed, ref } from 'vue';
import type { ZodError } from 'zod';

/** Flatten Zod issues to a first-message-per-path map (dot paths for nested fields). */
export function zodErrorToFieldMap(error: ZodError): Record<string, string> {
    const mapped: Record<string, string> = {};
    for (const issue of error.issues) {
        const key = issue.path.join('.') || '_form';
        if (!mapped[key]) {
            mapped[key] = issue.message;
        }
    }

    return mapped;
}

/** Shared client + server field error state for Inertia forms. */
export function useFieldErrors() {
    const fieldErrors = ref<Record<string, string>>({});

    const setFromZod = (error: ZodError) => {
        fieldErrors.value = zodErrorToFieldMap(error);
    };

    const setFromServer = (errors: Record<string, string | string[]>) => {
        const mapped: Record<string, string> = {};
        for (const [key, val] of Object.entries(errors)) {
            mapped[key] = Array.isArray(val) ? val.join(' ') : String(val);
        }
        fieldErrors.value = mapped;
    };

    const clear = () => {
        fieldErrors.value = {};
    };

    const clearField = (path: string) => {
        if (!fieldErrors.value[path]) {
            return;
        }
        const next = { ...fieldErrors.value };
        delete next[path];
        fieldErrors.value = next;
    };

    const messages = computed(() => [...new Set(Object.values(fieldErrors.value).filter(Boolean))]);

    return {
        fieldErrors,
        setFromZod,
        setFromServer,
        clear,
        clearField,
        messages,
    };
}
