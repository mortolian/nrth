<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type GlOption = { id: number; code: string; name: string; label: string };

type AccountRow = {
    id: number;
    name: string;
    bank_name: string | null;
    account_number_last4: string | null;
    currency: string;
    type: string | null;
    is_active: boolean;
    gl_account_id: number | null;
    gl_label: string | null;
};

const props = defineProps<{
    accounts: AccountRow[];
    gl_options: GlOption[];
}>();

const showForm = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    bank_name: '',
    account_number_last4: '',
    currency: 'ZAR',
    type: '',
    gl_account_id: '' as string | number,
    is_active: true,
});

const editingAccount = computed(() =>
    editingId.value === null ? null : props.accounts.find((row) => row.id === editingId.value) ?? null,
);

const glSelectOptions = computed(() => {
    const options = props.gl_options.map((option) => ({
        label: option.label,
        value: String(option.id),
    }));
    const current = editingAccount.value;
    if (current?.gl_account_id && current.gl_label) {
        const already = options.some((option) => option.value === String(current.gl_account_id));
        if (!already) {
            options.unshift({
                label: current.gl_label,
                value: String(current.gl_account_id),
            });
        }
    }

    return options;
});

const resetForm = () => {
    form.reset();
    form.clearErrors();
    form.currency = 'ZAR';
    form.is_active = true;
    form.gl_account_id = '';
    editingId.value = null;
};

const openCreate = () => {
    resetForm();
    showForm.value = true;
};

const openEdit = (account: AccountRow) => {
    editingId.value = account.id;
    showForm.value = true;
    form.name = account.name;
    form.bank_name = account.bank_name ?? '';
    form.account_number_last4 = account.account_number_last4 ?? '';
    form.currency = account.currency;
    form.type = account.type ?? '';
    form.gl_account_id = account.gl_account_id ? String(account.gl_account_id) : '';
    form.is_active = account.is_active;
    form.clearErrors();
};

const cancelForm = () => {
    showForm.value = false;
    resetForm();
};

const submit = () => {
    const payload = {
        name: form.name,
        bank_name: form.bank_name || null,
        account_number_last4: form.account_number_last4 || null,
        currency: form.currency || 'ZAR',
        type: form.type || null,
        gl_account_id: Number(form.gl_account_id),
        is_active: form.is_active,
    };

    if (editingId.value !== null) {
        form.transform(() => payload).put(route('banking.accounts.update', editingId.value), {
            preserveScroll: true,
            onSuccess: () => {
                cancelForm();
            },
        });
        return;
    }

    form.transform(() => ({
        name: payload.name,
        bank_name: payload.bank_name,
        account_number_last4: payload.account_number_last4,
        currency: payload.currency,
        type: payload.type,
        gl_account_id: payload.gl_account_id,
    })).post(route('banking.accounts.store'), {
        preserveScroll: true,
        onSuccess: () => {
            cancelForm();
        },
    });
};
</script>

<template>
    <AppLayout
        title="Banking accounts"
        :breadcrumbs="[
            { label: 'Banking' },
            { label: 'Accounts' },
        ]"
    >
        <PageHeader title="Banking accounts">
            <template #actions>
                <AppButton variant="secondary" @click="router.visit(route('banking.transactions.index'))">
                    View transactions
                </AppButton>
                <AppButton variant="secondary" @click="router.visit(route('banking.import.create'))">
                    Import statement
                </AppButton>
                <AppButton variant="primary" @click="showForm ? cancelForm() : openCreate()">
                    {{ showForm ? 'Cancel' : 'New account' }}
                </AppButton>
            </template>
        </PageHeader>

        <p class="mt-3 max-w-3xl text-sm text-slate-600">
            Banking accounts are used for statement import and for posting expenses and invoice payments once linked to a ledger account.
        </p>

        <AppCard v-if="showForm" class="mt-5">
            <h2 class="text-sm font-semibold text-slate-900">
                {{ editingId ? 'Edit banking account' : 'Create banking account' }}
            </h2>
            <form class="mt-4 grid gap-4 md:grid-cols-2" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Name</label>
                    <AppInput v-model="form.name" required />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Bank name</label>
                    <AppInput v-model="form.bank_name" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Last 4 digits</label>
                    <AppInput v-model="form.account_number_last4" maxlength="4" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Currency</label>
                    <AppInput v-model="form.currency" maxlength="3" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Type</label>
                    <AppInput v-model="form.type" placeholder="cheque, savings, credit_card, cash..." />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Ledger account</label>
                    <AppSelect
                        :model-value="String(form.gl_account_id || '')"
                        :options="glSelectOptions"
                        placeholder="Select GL account"
                        @update:model-value="form.gl_account_id = $event"
                    />
                    <p class="mt-1 text-xs text-slate-500">Required for expense Paid from and invoice Paid into.</p>
                    <p v-if="form.errors.gl_account_id" class="mt-1 text-xs text-red-600">{{ form.errors.gl_account_id }}</p>
                </div>
                <div v-if="editingId" class="md:col-span-2 flex items-center gap-2">
                    <input id="banking-active" v-model="form.is_active" type="checkbox" class="rounded border-slate-300">
                    <label for="banking-active" class="text-sm text-slate-700">Active</label>
                </div>
                <div class="md:col-span-2">
                    <AppButton type="submit" variant="primary" :disabled="form.processing">
                        {{ editingId ? 'Save changes' : 'Save account' }}
                    </AppButton>
                </div>
            </form>
        </AppCard>

        <AppCard class="mt-5">
            <AppTable
                v-if="accounts.length"
                :columns="[
                    { key: 'name', label: 'Name' },
                    { key: 'bank', label: 'Bank' },
                    { key: 'last4', label: 'Last 4' },
                    { key: 'currency', label: 'Currency' },
                    { key: 'type', label: 'Type' },
                    { key: 'gl', label: 'Ledger' },
                    { key: 'status', label: 'Status' },
                    { key: 'actions', label: '' },
                ]"
            >
                <tr v-for="account in accounts" :key="account.id" class="border-t border-slate-100">
                    <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ account.name }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ account.bank_name || '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ account.account_number_last4 || '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ account.currency }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ account.type || '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ account.gl_label || 'Not linked' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ account.is_active ? 'Active' : 'Inactive' }}</td>
                    <td class="px-4 py-3 text-right">
                        <AppButton type="button" variant="secondary" size="sm" @click="openEdit(account)">
                            Edit
                        </AppButton>
                    </td>
                </tr>
            </AppTable>
            <p v-else class="p-6 text-sm text-slate-500">
                No banking accounts yet. Create one and link it to a ledger account to post expenses and invoice payments.
            </p>
        </AppCard>
    </AppLayout>
</template>
