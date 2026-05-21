<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type PreviewRow = {
    transaction_date: string;
    description: string;
    reference: string | null;
    amount: string;
    direction: string;
};

type Summary = {
    total: number;
    new: number;
    duplicates: number;
    errors: number;
    preview: PreviewRow[];
};

const props = defineProps<{
    bankImport: {
        id: number;
        original_filename: string;
        status: string;
        account: { id: number; name: string; currency: string };
    };
    summary: Summary;
    canConfirm: boolean;
}>();

const pageTitle = computed(() => `Import preview — ${props.bankImport.original_filename}`);

const confirmImport = (importId: number) => {
    router.post(route('banking.import.confirm', importId));
};
</script>

<template>
    <AppLayout
        title="Import preview"
        :breadcrumbs="[
            { label: 'Banking', href: route('banking.transactions.index') },
            { label: 'Import statement', href: route('banking.import.create') },
            { label: 'Preview' },
        ]"
    >
        <PageHeader :title="pageTitle">
            <template #subtitle>
                <p class="text-sm text-slate-500">{{ bankImport.account.name }} · {{ bankImport.account.currency }}</p>
            </template>
        </PageHeader>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <AppCard>
                <p class="text-xs font-medium uppercase text-slate-500">Total found</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ summary.total }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase text-slate-500">New transactions</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-700">{{ summary.new }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase text-slate-500">Duplicates</p>
                <p class="mt-1 text-2xl font-semibold text-amber-700">{{ summary.duplicates }}</p>
            </AppCard>
            <AppCard>
                <p class="text-xs font-medium uppercase text-slate-500">Parse errors</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ summary.errors }}</p>
            </AppCard>
        </div>

        <AppCard class="mt-5 overflow-x-auto">
            <h2 class="text-sm font-semibold text-slate-900">Transaction preview</h2>
            <table class="mt-3 min-w-full text-left text-sm">
                <thead>
                    <tr>
                        <th class="border-b border-slate-200 px-3 py-2 font-medium text-slate-600">Date</th>
                        <th class="border-b border-slate-200 px-3 py-2 font-medium text-slate-600">Description</th>
                        <th class="border-b border-slate-200 px-3 py-2 font-medium text-slate-600">Reference</th>
                        <th class="border-b border-slate-200 px-3 py-2 font-medium text-slate-600 text-right">Amount</th>
                        <th class="border-b border-slate-200 px-3 py-2 font-medium text-slate-600">Type</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in summary.preview" :key="index" class="border-b border-slate-100">
                        <td class="px-3 py-2 text-slate-700">{{ row.transaction_date }}</td>
                        <td class="px-3 py-2 text-slate-700">{{ row.description }}</td>
                        <td class="px-3 py-2 text-slate-500">{{ row.reference || '—' }}</td>
                        <td class="px-3 py-2 text-right font-medium text-slate-900">{{ row.amount }}</td>
                        <td class="px-3 py-2 capitalize text-slate-600">{{ row.direction }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!summary.preview.length" class="mt-4 text-sm text-slate-500">No transactions to preview.</p>
        </AppCard>

        <div class="mt-5 flex gap-3">
            <AppButton
                v-if="canConfirm"
                variant="primary"
                @click="confirmImport(bankImport.id)"
            >
                Confirm import
            </AppButton>
            <AppButton variant="secondary" @click="router.visit(route('banking.import.create'))">
                Cancel
            </AppButton>
        </div>
    </AppLayout>
</template>
