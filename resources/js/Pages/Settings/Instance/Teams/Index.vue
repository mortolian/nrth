<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import InstanceSettingsShell from '@/Components/InstanceSettingsShell.vue';

type TeamRow = {
    id: number;
    name: string;
    personal_team: boolean;
    owner_name: string | null;
    owner_email: string | null;
    members_count: number;
    manage_url: string;
};

defineProps<{
    teams: TeamRow[];
}>();
</script>

<template>
    <InstanceSettingsShell section="teams">
        <AppCard>
            <h3 class="text-base font-semibold text-slate-900">All businesses</h3>
            <p class="mt-1 max-w-2xl text-sm text-slate-500">
                Instance operators can open any business to manage members, roles, and invitations — even if you are not a member yourself.
            </p>

            <AppTable
                class="mt-4"
                table-class="text-sm"
                :columns="[
                    { key: 'id', label: 'Business ID', widthClass: 'whitespace-nowrap tabular-nums' },
                    { key: 'name', label: 'Business' },
                    { key: 'owner', label: 'Owner' },
                    { key: 'members', label: 'Members', widthClass: 'whitespace-nowrap tabular-nums' },
                    { key: 'actions', label: '', widthClass: 'text-right' },
                ]"
            >
                <tr v-for="team in teams" :key="team.id" class="border-t border-slate-100">
                    <td class="px-3 py-2 font-mono tabular-nums text-slate-700">{{ team.id }}</td>
                    <td class="px-3 py-2">
                        <div class="font-medium text-slate-900">{{ team.name }}</div>
                        <div v-if="team.personal_team" class="text-xs text-slate-500">Personal business</div>
                    </td>
                    <td class="px-3 py-2 text-slate-600">
                        <div v-if="team.owner_name">{{ team.owner_name }}</div>
                        <div v-if="team.owner_email" class="text-xs text-slate-500">{{ team.owner_email }}</div>
                        <span v-else class="text-slate-400">—</span>
                    </td>
                    <td class="px-3 py-2 text-slate-700">{{ team.members_count }}</td>
                    <td class="px-3 py-2 text-right">
                        <Link
                            :href="team.manage_url"
                            class="text-sm font-medium text-brand-700 hover:text-brand-800"
                        >
                            Manage members
                        </Link>
                    </td>
                </tr>
                <template v-if="teams.length === 0" #empty>
                    <EmptyState title="No businesses yet" description="Businesses appear here when users create them." />
                </template>
            </AppTable>
        </AppCard>
    </InstanceSettingsShell>
</template>
