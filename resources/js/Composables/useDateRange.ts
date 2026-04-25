import { computed, ref } from 'vue';

type Preset = 'this_month' | 'last_month' | 'this_quarter' | 'this_tax_year' | 'last_tax_year';

function startOfDay(d: Date): Date {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}

export function useDateRange() {
    const today = startOfDay(new Date());
    const from = ref<Date>(new Date(today.getFullYear(), today.getMonth(), 1));
    const to = ref<Date>(today);

    const setPreset = (preset: Preset) => {
        const now = startOfDay(new Date());
        if (preset === 'this_month') {
            from.value = new Date(now.getFullYear(), now.getMonth(), 1);
            to.value = now;
            return;
        }

        if (preset === 'last_month') {
            from.value = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            to.value = new Date(now.getFullYear(), now.getMonth(), 0);
            return;
        }

        if (preset === 'this_quarter') {
            const quarterStartMonth = Math.floor(now.getMonth() / 3) * 3;
            from.value = new Date(now.getFullYear(), quarterStartMonth, 1);
            to.value = now;
            return;
        }

        const taxYearStart = now.getMonth() >= 2
            ? new Date(now.getFullYear(), 2, 1)
            : new Date(now.getFullYear() - 1, 2, 1);

        if (preset === 'this_tax_year') {
            from.value = taxYearStart;
            to.value = now;
            return;
        }

        from.value = new Date(taxYearStart.getFullYear() - 1, 2, 1);
        to.value = new Date(taxYearStart.getFullYear(), 1, 28);
    };

    const range = computed(() => ({
        from: from.value,
        to: to.value,
    }));

    return {
        from,
        to,
        range,
        setPreset,
    };
}
