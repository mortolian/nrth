<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { cn } from '@/lib/utils';
import {
    allIsoCodesSorted,
    buildE164,
    countryFlag,
    getDialForIso,
    parseE164Digits,
} from '@/lib/phoneMeta';

const model = defineModel<string>({ default: '' });

const props = defineProps<{
    disabled?: boolean;
    /** ISO 3166-1 alpha-2 (e.g. ZA). */
    defaultCountry?: string;
    placeholder?: string;
}>();

const preferredOrder = ['ZA', 'GB', 'US', 'AU'] as const;

const defaultCountryResolved = computed(() => (props.defaultCountry ?? 'ZA').toUpperCase());

const countryOptions = computed(() =>
    allIsoCodesSorted(preferredOrder).map((iso2) => ({
        value: iso2,
        label: `${countryFlag(iso2)} +${getDialForIso(iso2)} ${iso2}`,
    })),
);

const selectedCountry = ref(defaultCountryResolved.value);
const nationalNumber = ref('');

function applyExternalModel(e164: string): void {
    const t = e164.trim();
    if (!t) {
        nationalNumber.value = '';
        selectedCountry.value = defaultCountryResolved.value;

        return;
    }
    const digits = t.replace(/^\+/, '').replace(/\D/g, '');
    const parsed = parseE164Digits(digits);
    if (parsed) {
        selectedCountry.value = parsed.iso;
        nationalNumber.value = parsed.nationalDigits;

        return;
    }
    nationalNumber.value = t.replace(/^\+/, '');
}

watch(
    () => model.value,
    (v) => {
        applyExternalModel(v ?? '');
    },
    { immediate: true },
);

watch(defaultCountryResolved, (c) => {
    if (!model.value?.trim()) {
        selectedCountry.value = c;
    }
});

function onNationalInput(): void {
    const next = buildE164(selectedCountry.value, nationalNumber.value);
    model.value = next;
}

watch(selectedCountry, () => {
    onNationalInput();
});
</script>

<template>
    <div
        :class="
            cn(
                'flex min-w-0 rounded-md border border-slate-300 bg-white ring-slate-300 transition focus-within:ring-2',
                props.disabled && 'cursor-not-allowed bg-slate-100',
            )
        "
    >
        <select
            v-model="selectedCountry"
            :disabled="disabled"
            class="max-w-[9.5rem] shrink-0 cursor-pointer border-0 border-r border-slate-300 bg-transparent py-2 pl-2 pr-1 text-xs outline-none sm:max-w-[11rem] sm:text-sm"
            :class="props.disabled ? 'cursor-not-allowed' : ''"
            aria-label="Country calling code"
        >
            <option v-for="opt in countryOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
            </option>
        </select>
        <input
            v-model="nationalNumber"
            type="tel"
            autocomplete="tel-national"
            :disabled="disabled"
            :placeholder="placeholder ?? 'Phone number'"
            :class="
                cn(
                    'min-w-0 flex-1 border-0 bg-transparent px-3 py-2 text-sm outline-none',
                    props.disabled ? 'cursor-not-allowed' : '',
                )
            "
            @input="onNationalInput"
        >
    </div>
</template>
