<script setup lang="ts">
import { computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type AccountTypeOpt = { value: string; label: string };
type ParentOpt = { id: number; code: string; name: string; type: string };

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
}>();

const isSystem = computed(() => props.account?.is_system ?? false);

const form = useForm({
    code: props.account?.code ?? '',
    name: props.account?.name ?? '',
    description: props.account?.description ?? '',
    type: props.account?.type ?? 'expense',
    parent_id: props.account?.parent_id != null ? String(props.account.parent_id) : '',
    is_active: props.account?.is_active ?? true,
});

const parentChoices = computed(() => props.parent_options.filter((p) => p.type === form.type));

watch(
    () => form.type,
    () => {
        const ok = parentChoices.value.some((p) => String(p.id) === form.parent_id);
        if (!ok) {
            form.parent_id = '';
        }
    },
);

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
                    <label class="mb-1 block text-xs font-medium text-slate-500">Code</label>
                    <AppInput v-model="form.code" class="font-mono" :disabled="isSystem" required />
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
