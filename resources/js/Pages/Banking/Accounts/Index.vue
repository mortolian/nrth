<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type AccountRow = {
    id: number;
    name: string;
    bank_name: string | null;
    account_number_last4: string | null;
    currency: string;
    type: string | null;
    is_active: boolean;
};

defineProps<{
    accounts: AccountRow[];
}>();

const showForm = ref(false);

const form = useForm({
    name: '',
    bank_name: '',
    account_number_last4: '',
    currency: 'ZAR',
    type: '',
});

const submit = () => {
    form.post(route('banking.accounts.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
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
                <AppButton variant="primary" @click="showForm = !showForm">
                    {{ showForm ? 'Cancel' : 'New account' }}
                </AppButton>
            </template>
        </PageHeader>

        <AppCard v-if="showForm" class="mt-5">
            <h2 class="text-sm font-semibold text-slate-900">Create import account</h2>
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
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Type</label>
                    <AppInput v-model="form.type" placeholder="cheque, savings, credit_card..." />
                </div>
                <div class="md:col-span-2">
                    <AppButton type="submit" variant="primary" :disabled="form.processing">Save account</AppButton>
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
                ]"
            >
                <tr v-for="account in accounts" :key="account.id" class="border-t border-slate-100">
                    <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ account.name }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ account.bank_name || '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ account.account_number_last4 || '—' }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ account.currency }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ account.type || '—' }}</td>
                </tr>
            </AppTable>
            <p v-else class="p-6 text-sm text-slate-500">
                No import accounts yet. Create one to start importing bank statements.
            </p>
        </AppCard>
    </AppLayout>
</template>
