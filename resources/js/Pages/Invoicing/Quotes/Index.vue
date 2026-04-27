<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppCard from '@/Components/AppCard.vue';
</script>

<template>
    <AppLayout title="Quotes" :breadcrumbs="[{ label: 'Money In' }, { label: 'Quotes' }]">
        <Head title="Quotes" />

        <PageHeader
            title="Quotes"
            subtitle="Draft and send quotes to clients before converting them into invoices."
        />

        <AppCard class="mt-6">
            <h2 class="text-base font-semibold text-slate-900">Coming soon</h2>
            <p class="mt-2 text-sm text-slate-600">
                Quotes are not fully implemented yet. You can continue creating invoices directly for now.
            </p>
            <div class="mt-4">
                <Link
                    :href="route('invoicing.invoices.index')"
                    class="inline-flex items-center rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    Go to invoices
                </Link>
            </div>
        </AppCard>
    </AppLayout>
</template>

