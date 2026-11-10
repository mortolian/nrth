<script setup lang="ts">
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { useReportsTabs } from '@/Composables/useFeatureTabs';

const reportsTabs = useReportsTabs();

type SavedReport = {
    id: number;
    name: string;
    rules_count: number;
    updated_at: string | null;
};

const props = defineProps<{
    reports: SavedReport[];
}>();

const page = usePage<{ team_permissions?: string[] }>();
const canManage = computed(() => (page.props.team_permissions ?? []).includes('reports.manage'));

const open = (report: SavedReport) => {
    router.visit(route('reports.statement-filters.show', report.id));
};
</script>

<template>
    <FeatureShell
        title="Reports"
        section="statement-filters"
        :tabs="reportsTabs"
        document-title="Statement filters"
        subtitle="Named matches over imported bank statement lines"
    >
        <template v-if="canManage" #actions>
            <AppButton as="a" :href="route('reports.statement-filters.create')">New report</AppButton>
        </template>

        <AppCard v-if="props.reports.length === 0">
            <EmptyState
                title="No statement filter reports"
                description="Save a filter such as Woolworths, give it an alias, and total the imported lines that match."
            >
                <template v-if="canManage" #action>
                    <AppButton as="a" :href="route('reports.statement-filters.create')">New report</AppButton>
                </template>
            </EmptyState>
        </AppCard>

        <AppCard v-else>
            <AppTable
                dense
                embedded
                table-class="text-sm"
                :columns="[
                    { key: 'name', label: 'Report' },
                    { key: 'filters', label: 'Filters', align: 'right' },
                    { key: 'updated', label: 'Updated' },
                ]"
                :show-pagination="false"
            >
                <tr
                    v-for="report in props.reports"
                    :key="report.id"
                    class="cursor-pointer hover:bg-slate-50"
                    @click="open(report)"
                >
                    <td class="px-3 py-2 font-medium text-slate-900">{{ report.name }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ report.rules_count }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ report.updated_at ?? '—' }}</td>
                </tr>
            </AppTable>
        </AppCard>
    </FeatureShell>
</template>
