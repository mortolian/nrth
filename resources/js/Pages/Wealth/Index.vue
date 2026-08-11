<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { PiggyBank } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import AppButton from '@/Components/AppButton.vue';
</script>

<template>
    <AppLayout
        title="Wealth"
        :breadcrumbs="[{ label: 'Wealth' }]"
    >
        <PageHeader
            title="Wealth"
            subtitle="Optional module for net worth and investments."
        />

        <div class="mt-6">
            <EmptyState
                title="Wealth is coming soon"
                description="This is a placeholder. Enable or disable the Wealth module under Settings → Features. Domain features will land in a follow-up."
                :icon="PiggyBank"
            >
                <template #action>
                    <Link :href="route('settings.features')">
                        <AppButton variant="secondary" size="sm">
                            Manage features
                        </AppButton>
                    </Link>
                </template>
            </EmptyState>
        </div>
    </AppLayout>
</template>
