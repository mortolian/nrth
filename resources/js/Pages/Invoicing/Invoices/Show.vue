<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{
    invoice: {
        id: number;
        number: string;
    };
}>();
</script>

<template>
    <AppLayout
        title="Invoice"
        :breadcrumbs="[
            { label: 'Invoicing' },
            { label: 'Invoices', href: route('invoicing.invoices.index') },
            { label: invoice.number },
        ]"
    >
        <PageHeader :title="invoice.number" subtitle="Invoice details page scaffolded for upcoming phase" />
        <AppCard class="mt-4">
            <p class="text-sm text-slate-600">
                This page is intentionally lightweight for now. UI-3.1 list navigation now resolves here.
            </p>
        </AppCard>
    </AppLayout>
</template>
