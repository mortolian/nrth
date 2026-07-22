<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { CircleHelp } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

type AccountTypeOpt = { value: string; label: string };
type ParentOpt = { id: number; code: string; name: string; type: string };
type CodeAccount = { id: number; code: string; type: string; parent_id: number | null };

const props = defineProps<{
    isEditing: boolean;
    account: null | {
        id: number;
        code: string;
        name: string;
        description: string | null;
        type: string;
        parent_id: number | null;
        is_system: boolean;
        is_active: boolean;
    };
    account_types: AccountTypeOpt[];
    parent_options: ParentOpt[];
    suggested_codes?: Record<string, string> | null;
    code_accounts?: CodeAccount[];
}>();

const isSystem = computed(() => props.account?.is_system ?? false);
const codeAccounts = computed(() => props.code_accounts ?? []);
const suggestedCodes = computed(() => props.suggested_codes ?? {});

const defaultType = props.account?.type ?? 'expense';
const initialCode = props.isEditing
    ? (props.account?.code ?? '')
    : (suggestedCodes.value[defaultType] ?? '');

const form = useForm({
    code: initialCode,
    name: props.account?.name ?? '',
    description: props.account?.description ?? '',
    type: defaultType,
    parent_id: props.account?.parent_id != null ? String(props.account.parent_id) : '',
    is_active: props.account?.is_active ?? true,
});

/** Once the user edits the code, stop overwriting it when type/parent change. */
const codeCustomized = ref(props.isEditing);

const parentChoices = computed(() => props.parent_options.filter((p) => p.type === form.type));

const numericCode = (code: string): number | null => {
    const trimmed = code.trim();
    return /^\d+$/.test(trimmed) ? Number(trimmed) : null;
};

const typeBase = (type: string): number => {
    switch (type) {
        case 'asset': return 1000;
        case 'liability': return 2000;
        case 'equity': return 3000;
        case 'income': return 4000;
        case 'expense': return 5000;
        default: return 5000;
    }
};

const suggestCode = (type: string, parentId: string): string => {
    const used = new Set(codeAccounts.value.map((a) => a.code));
    const base = typeBase(type);
    let next: number;

    if (parentId !== '') {
        const parentNumericId = Number(parentId);
        const parent = props.parent_options.find((p) => p.id === parentNumericId);
        const siblingNumbers = codeAccounts.value
            .filter((a) => a.parent_id === parentNumericId)
            .map((a) => numericCode(a.code))
            .filter((n): n is number => n !== null);
        const parentNumber = parent ? numericCode(parent.code) : null;
        const max = siblingNumbers.length ? Math.max(...siblingNumbers) : null;

        if (max !== null) {
            next = max + 10;
        } else if (parentNumber !== null) {
            next = parentNumber + 10;
        } else {
            next = base;
        }
    } else if (suggestedCodes.value[type]) {
        return suggestedCodes.value[type];
    } else {
        const typeNumbers = codeAccounts.value
            .filter((a) => a.type === type)
            .map((a) => numericCode(a.code))
            .filter((n): n is number => n !== null);
        const max = typeNumbers.length ? Math.max(...typeNumbers) : null;
        next = max !== null ? max + 10 : base;
        if (next < base) next = base;
    }

    while (used.has(String(next))) {
        next += 10;
    }

    return String(next);
};

const applySuggestedCode = () => {
    if (props.isEditing || codeCustomized.value) return;
    form.code = suggestCode(form.type, form.parent_id);
};

watch(
    () => form.type,
    () => {
        const ok = parentChoices.value.some((p) => String(p.id) === form.parent_id);
        if (!ok) {
            form.parent_id = '';
        }
        applySuggestedCode();
    },
);

watch(
    () => form.parent_id,
    () => applySuggestedCode(),
);

const onCodeInput = () => {
    if (!props.isEditing) {
        codeCustomized.value = true;
    }
};

const parentSelectOptions = computed(() =>
    parentChoices.value.map((p) => ({
        label: `${p.code} – ${p.name}`,
        value: String(p.id),
    })),
);

const typeSelectOptions = computed(() => props.account_types.map((t) => ({ label: t.label, value: t.value })));

const submit = () => {
    if (props.isEditing && props.account) {
        form.clearErrors();
        form
            .transform((data) => ({
                code: data.code,
                name: data.name,
                description: data.description ? String(data.description) : null,
                parent_id: data.parent_id === '' || data.parent_id === null ? null : Number(data.parent_id),
                ...(!isSystem.value ? { is_active: Boolean(data.is_active) } : {}),
            }))
            .put(route('accounting.accounts.update', props.account.id));
        return;
    }

    form.clearErrors();
    form
        .transform((data) => ({
            code: data.code,
            name: data.name,
            description: data.description ? String(data.description) : null,
            type: data.type,
            parent_id: data.parent_id === '' || data.parent_id === null ? null : Number(data.parent_id),
        }))
        .post(route('accounting.accounts.store'));
};

const hasFormErrors = computed(() => Object.keys(form.errors).length > 0);
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit Account' : 'New Account'"
        :breadcrumbs="[
            { label: 'Accounting' },
            { label: 'Chart of Accounts', href: route('accounting.accounts.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader
            :title="isEditing ? 'Edit account' : 'Add account'"
            :subtitle="isSystem ? 'System accounts: you can only change the description.' : 'Custom accounts for your business'"
        />

        <AppCard class="mt-5">
            <form class="grid max-w-xl gap-4" @submit.prevent="submit">
                <div>
                    <div class="mb-1 flex items-center gap-1.5">
                        <label class="text-xs font-medium text-slate-500" for="account-code">Code</label>
                        <span class="group relative inline-flex">
                            <button
                                type="button"
                                class="inline-flex rounded text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                aria-label="What is an account code?"
                            >
                                <CircleHelp class="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                            <span
                                role="tooltip"
                                class="pointer-events-none absolute left-0 top-full z-50 mt-2 w-72 rounded-md bg-slate-900 px-3 py-2 text-xs leading-relaxed text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100 group-focus-within:opacity-100"
                            >
                                A short reference used in journals, reports, and mappings. Codes are usually numeric and grouped by type (1000s assets, 2000s liabilities, 5000s expenses, and so on). A suggestion is filled in for you — change it if your chart uses a different scheme.
                            </span>
                        </span>
                    </div>
                    <AppInput
                        id="account-code"
                        v-model="form.code"
                        class="font-mono"
                        :disabled="isSystem"
                        required
                        @update:model-value="onCodeInput"
                    />
                    <p v-if="!isEditing && !codeCustomized" class="mt-1 text-xs text-slate-500">
                        Suggested next code — edit anytime.
                    </p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Name</label>
                    <AppInput v-model="form.name" :disabled="isSystem" required />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Description</label>
                    <textarea
                        v-model="form.description"
                        rows="3"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        placeholder="Optional"
                    />
                </div>
                <div v-if="!isEditing">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Type</label>
                    <AppSelect v-model="form.type" :options="typeSelectOptions" />
                </div>
                <div v-else class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    <span class="text-xs font-medium text-slate-500">Type</span>
                    <p class="mt-0.5 capitalize">{{ form.type }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Parent account</label>
                    <AppSelect
                        v-model="form.parent_id"
                        :options="[{ label: '— None —', value: '' }, ...parentSelectOptions]"
                        :disabled="isSystem"
                    />
                    <p class="mt-1 text-xs text-slate-500">Parent must be the same account type.</p>
                </div>
                <div v-if="isEditing && !isSystem" class="flex items-center gap-2">
                    <input id="acc-active" v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                    <label for="acc-active" class="text-sm text-slate-700">Account is active</label>
                </div>

                <div v-if="hasFormErrors" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    <ul class="list-inside list-disc">
                        <li v-for="(err, key) in form.errors" :key="key">{{ err }}</li>
                    </ul>
                </div>

                <div class="flex flex-wrap gap-3">
                    <AppButton variant="primary" type="submit" :disabled="form.processing">
                        {{ isEditing ? 'Save changes' : 'Create account' }}
                    </AppButton>
                    <AppButton variant="ghost" type="button" @click="router.visit(route('accounting.accounts.index'))">
                        Cancel
                    </AppButton>
                </div>
            </form>
        </AppCard>
    </AppLayout>
</template>
