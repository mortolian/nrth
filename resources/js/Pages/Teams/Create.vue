<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import CreateTeamForm from '@/Pages/Teams/Partials/CreateTeamForm.vue';
</script>

<template>
    <AppLayout
        title="Create business"
        :breadcrumbs="[
            { label: 'Dashboard', href: route('dashboard') },
            { label: 'Create business' },
        ]"
    >
        <PageHeader
            title="Create business"
            subtitle="Add another business with its own books, clients, and settings."
        />

        <CreateTeamForm class="mt-5" />
    </AppLayout>
</template>
