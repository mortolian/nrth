<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import DialogModal from '@/Components/DialogModal.vue';
import { useToast } from '@/Composables/useToast';

type CategoryInput = {
    id: number;
    name: string;
    envelope_cents: number;
    account_id: number | null;
} | null;

const props = defineProps<{
    show: boolean;
    budgetId: number;
    budgetCurrency: string;
    category: CategoryInput;
    expenseAccounts: Array<{ id: number; name: string }>;
}>();

const emit = defineEmits<{
    close: [];
}>();

const toast = useToast();
const processing = ref(false);
const errors = reactive<Record<string, string>>({});

const form = reactive({
    name: '',
    envelope_major: '',
    account_id: '' as string,
});

/** Match Brick default fraction digits used elsewhere in budgeting. */
function minorDigits(code: string): number {
    const c = code.toUpperCase();
    if (c === 'JPY' || c === 'KRW' || c === 'VND' || c === 'CLP' || c === 'UGX') return 0;
    if (c === 'BHD' || c === 'IQD' || c === 'JOD' || c === 'KWD' || c === 'LYD' || c === 'OMR' || c === 'TND') return 3;
    return 2;
}

function centsToMajorStr(cents: number, ccy: string): string {
    const d = minorDigits(ccy);
    return ((Number(cents) || 0) / 10 ** d).toFixed(d);
}

function normalizeMajorAmountForParse(raw: string): string {
    let s = raw.trim().replace(/\s/g, '');
    if (s === '' || s === '-') return s;
    const hasComma = s.includes(',');
    const hasDot = s.includes('.');
    if (hasComma && hasDot) {
        const lastComma = s.lastIndexOf(',');
        const lastDot = s.lastIndexOf('.');
        if (lastDot > lastComma) {
            s = s.replace(/,/g, '');
        } else {
            s = s.replace(/\./g, '').replace(',', '.');
        }
    } else if (hasComma && !hasDot) {
        const parts = s.split(',');
        if (parts.length === 2 && parts[1].length <= 3) {
            s = `${parts[0].replace(/\./g, '')}.${parts[1]}`;
        } else {
            s = s.replace(/,/g, '');
        }
    }
    return s.replace(/[^0-9.-]/g, '');
}

function majorStrToCents(raw: string, ccy: string): number | null {
    const normalized = normalizeMajorAmountForParse(raw);
    if (normalized === '' || normalized === '-') return null;
    const n = Number(normalized);
    if (!Number.isFinite(n) || n < 0) return null;
    const d = minorDigits(ccy);
    return Math.round(n * 10 ** d);
}

const isEditing = computed(() => props.category != null);

const accountOptions = computed(() => [
    { label: 'No linked account', value: '' },
    ...props.expenseAccounts.map((a) => ({ label: a.name, value: String(a.id) })),
]);

watch(
    () => [props.show, props.category] as const,
    ([show]) => {
        if (!show) return;
        Object.keys(errors).forEach((k) => delete errors[k]);
        if (props.category) {
            form.name = props.category.name;
            form.envelope_major = centsToMajorStr(props.category.envelope_cents, props.budgetCurrency);
            form.account_id = props.category.account_id != null ? String(props.category.account_id) : '';
        } else {
            form.name = '';
            form.envelope_major = '';
            form.account_id = '';
        }
    },
);

const close = () => {
    if (processing.value) return;
    emit('close');
};

const submit = () => {
    if (processing.value) return;
    Object.keys(errors).forEach((k) => delete errors[k]);

    const name = form.name.trim();
    if (!name) {
        errors.name = 'Category name is required.';
        return;
    }

    const envelopeCents = majorStrToCents(form.envelope_major === '' ? '0' : form.envelope_major, props.budgetCurrency);
    if (envelopeCents === null) {
        errors.envelope_cents = 'Enter a valid envelope amount.';
        return;
    }

    const payload = {
        name,
        envelope_cents: envelopeCents,
        account_id: form.account_id === '' ? null : Number(form.account_id),
    };

    const visitOptions = {
        preserveScroll: true,
        onStart: () => {
            processing.value = true;
        },
        onSuccess: () => {
            toast.success(isEditing.value ? 'Category updated.' : 'Category added.');
            emit('close');
        },
        onError: (serverErrors: Record<string, string>) => {
            Object.assign(errors, serverErrors);
            if (!Object.keys(serverErrors).length) {
                toast.error('Could not save this category.');
            }
        },
        onFinish: () => {
            processing.value = false;
        },
    };

    if (isEditing.value && props.category) {
        router.put(
            route('budgeting.categories.update', [props.budgetId, props.category.id]),
            payload,
            visitOptions,
        );
        return;
    }

    router.post(route('budgeting.categories.store', props.budgetId), payload, visitOptions);
};
</script>

<template>
    <DialogModal :show="show" max-width="lg" @close="close">
        <template #title>
            {{ isEditing ? 'Edit category' : 'Add category' }}
        </template>
        <template #content>
            <div class="space-y-4 text-left text-slate-900">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        Name <span class="text-rose-600">*</span>
                    </label>
                    <AppInput v-model="form.name" placeholder="e.g. Operations" />
                    <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        Envelope ({{ budgetCurrency }})
                    </label>
                    <AppInput v-model="form.envelope_major" placeholder="0.00" inputmode="decimal" />
                    <p class="mt-1 text-xs text-slate-500">Period spending cap in budget currency.</p>
                    <p v-if="errors.envelope_cents" class="mt-1 text-xs text-rose-600">{{ errors.envelope_cents }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Linked expense account</label>
                    <AppSelect
                        :model-value="form.account_id"
                        :options="accountOptions"
                        @update:model-value="form.account_id = $event"
                    />
                    <p class="mt-1 text-xs text-slate-500">Optional — used to track linked ledger spend against this category.</p>
                </div>
            </div>
        </template>
        <template #footer>
            <div class="flex justify-end gap-2">
                <AppButton variant="secondary" :disabled="processing" @click="close">Cancel</AppButton>
                <AppButton variant="primary" :disabled="processing" @click="submit">
                    {{ isEditing ? 'Save category' : 'Add category' }}
                </AppButton>
            </div>
        </template>
    </DialogModal>
</template>
