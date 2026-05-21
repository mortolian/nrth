<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type AccountOption = {
    id: number;
    name: string;
    bank_name: string | null;
    currency: string;
};

defineProps<{
    accounts: AccountOption[];
}>();

const form = useForm<{
    account_id: number | '';
    file: File | null;
}>({
    account_id: '',
    file: null,
});

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    form.file = input.files?.[0] ?? null;
};

const submit = () => {
    form.post(route('banking.import.store'), {
        forceFormData: true,
    });
};
</script>

<template>
    <AppLayout
        title="Import bank statement"
        :breadcrumbs="[
            { label: 'Banking' },
            { label: 'Import statement', href: route('banking.import.create') },
        ]"
    >
        <PageHeader title="Import bank statement">
            <template #actions>
                <AppButton variant="secondary" @click="$inertia.visit(route('banking.accounts.index'))">
                    Manage accounts
                </AppButton>
            </template>
        </PageHeader>

        <AppCard class="mt-5 max-w-2xl">
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Account</label>
                    <AppSelect
                        :model-value="form.account_id === '' ? '' : String(form.account_id)"
                        :options="[
                            { label: 'Select account…', value: '' },
                            ...accounts.map((a) => ({
                                label: a.bank_name ? `${a.name} (${a.bank_name})` : a.name,
                                value: String(a.id),
                            })),
                        ]"
                        @update:model-value="form.account_id = $event === '' ? '' : Number($event)"
                    />
                    <p v-if="form.errors.account_id" class="mt-1 text-xs text-red-600">{{ form.errors.account_id }}</p>
                    <p v-if="!accounts.length" class="mt-2 text-xs text-amber-700">
                        Create an import account before uploading a statement.
                    </p>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Statement file (CSV, TXT, OFX — max 10MB)</label>
                    <input
                        type="file"
                        accept=".csv,.txt,.ofx,text/csv,text/plain,application/x-ofx"
                        class="block w-full text-sm text-slate-600 file:mr-4 file:rounded-md file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-brand-700"
                        @change="onFileChange"
                    />
                    <p v-if="form.errors.file" class="mt-1 text-xs text-red-600">{{ form.errors.file }}</p>
                </div>

                <AppButton type="submit" variant="primary" :disabled="form.processing || !accounts.length">
                    Continue
                </AppButton>
            </form>
        </AppCard>
    </AppLayout>
</template>
