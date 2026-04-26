<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

type Member = {
    id: number;
    name: string;
    email: string;
    profile_photo_url: string;
    role_key: string;
    role_label: string;
    is_owner: boolean;
};

type Invitation = { id: number; email: string; role_key: string; role_label: string };

type JetstreamRole = { key: string; name: string; description?: string };

const props = defineProps<{
    team: { id: number; name: string; personal_team: boolean };
    members: Member[];
    invitations: Invitation[];
    available_roles: JetstreamRole[];
    permissions: {
        canAddTeamMembers: boolean;
        canDeleteTeam: boolean;
        canRemoveTeamMembers: boolean;
        canUpdateTeam: boolean;
        canUpdateTeamMembers: boolean;
    };
    role_summaries: Array<{ key: string; title: string; description: string }>;
}>();

const page = usePage();
const authUserId = computed(() => (page.props.auth as { user?: { id: number } })?.user?.id);

const inviteForm = useForm({
    email: '',
    role: props.available_roles[0]?.key ?? 'accountant',
});

const submitInvite = () => {
    inviteForm.post(route('team-members.store', props.team.id), {
        errorBag: 'addTeamMember',
        preserveScroll: true,
        onSuccess: () => inviteForm.reset('email'),
    });
};

const cancelInvitation = (invitation: Invitation) => {
    router.delete(route('team-invitations.destroy', invitation.id), { preserveScroll: true });
};

const roleModalOpen = ref(false);
const roleTarget = ref<Member | null>(null);
const updateRoleForm = useForm({ role: '' as string });

const openRoleModal = (member: Member) => {
    roleTarget.value = member;
    updateRoleForm.role = member.role_key;
    roleModalOpen.value = true;
};

const saveRole = () => {
    if (!roleTarget.value) return;
    updateRoleForm.put(route('team-members.update', [props.team.id, roleTarget.value.id]), {
        preserveScroll: true,
        onSuccess: () => {
            roleModalOpen.value = false;
            roleTarget.value = null;
        },
    });
};

const removeTarget = ref<Member | null>(null);
const removeForm = useForm({});

const confirmRemove = (member: Member) => {
    removeTarget.value = member;
};

const removeMember = () => {
    if (!removeTarget.value) return;
    removeForm.delete(route('team-members.destroy', [props.team.id, removeTarget.value.id]), {
        errorBag: 'removeTeamMember',
        preserveScroll: true,
        onSuccess: () => {
            removeTarget.value = null;
        },
    });
};
</script>

<template>
    <AppLayout
        title="Team"
        :breadcrumbs="[
            { label: 'Settings', href: route('profile.show') },
            { label: 'Team' },
        ]"
    >
        <PageHeader title="Team & members" :subtitle="`Team: ${team.name}`">
            <template #actions>
                <Link
                    :href="route('teams.show', team.id)"
                    class="inline-flex items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50"
                >
                    Classic team screen
                </Link>
            </template>
        </PageHeader>

        <div class="mt-6 grid gap-4 md:grid-cols-3">
            <AppCard v-for="summary in role_summaries" :key="summary.key" class="border-slate-200">
                <h3 class="text-sm font-semibold text-slate-900">{{ summary.title }}</h3>
                <p class="mt-2 text-sm text-slate-600">{{ summary.description }}</p>
            </AppCard>
        </div>

        <AppCard v-if="permissions.canAddTeamMembers" class="mt-6">
            <h3 class="text-base font-semibold text-slate-900">Invite by email</h3>
            <p class="mt-1 text-sm text-slate-500">
                We’ll email an invitation link. The recipient accepts to join this team (Jetstream invitations).
            </p>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Email</label>
                    <AppInput v-model="inviteForm.email" type="email" placeholder="colleague@example.com" />
                    <p v-if="inviteForm.errors.email" class="mt-1 text-xs text-rose-600">{{ inviteForm.errors.email }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Role</label>
                    <AppSelect
                        :model-value="inviteForm.role"
                        :options="available_roles.map((r) => ({ label: r.name, value: r.key }))"
                        @update:model-value="inviteForm.role = $event"
                    />
                    <p v-if="inviteForm.errors.role" class="mt-1 text-xs text-rose-600">{{ inviteForm.errors.role }}</p>
                </div>
            </div>
            <div class="mt-4">
                <AppButton variant="primary" :disabled="inviteForm.processing" @click="submitInvite">Send invitation</AppButton>
            </div>
        </AppCard>

        <AppCard v-if="invitations.length && permissions.canAddTeamMembers" class="mt-6">
            <h3 class="text-base font-semibold text-slate-900">Pending invitations</h3>
            <ul class="mt-3 divide-y divide-slate-100">
                <li v-for="inv in invitations" :key="inv.id" class="flex items-center justify-between py-3 text-sm">
                    <div>
                        <span class="font-medium text-slate-900">{{ inv.email }}</span>
                        <span class="ml-2 text-slate-500">({{ inv.role_label }})</span>
                    </div>
                    <button
                        v-if="permissions.canRemoveTeamMembers"
                        type="button"
                        class="text-rose-600 hover:underline"
                        @click="cancelInvitation(inv)"
                    >
                        Revoke
                    </button>
                </li>
            </ul>
        </AppCard>

        <AppCard class="mt-6">
            <h3 class="text-base font-semibold text-slate-900">Team members</h3>
            <AppTable
                class="mt-4"
                :columns="[
                    { key: 'member', label: 'Member' },
                    { key: 'role', label: 'Role' },
                    { key: 'actions', label: '' },
                ]"
                :page="1"
                :last-page="1"
            >
                <tr v-for="m in members" :key="m.id" class="border-b border-slate-100">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <img :src="m.profile_photo_url" :alt="m.name" class="h-9 w-9 rounded-full object-cover">
                            <div>
                                <div class="font-medium text-slate-900">{{ m.name }}</div>
                                <div class="text-xs text-slate-500">{{ m.email }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <button
                            v-if="permissions.canUpdateTeamMembers && !m.is_owner && available_roles.length"
                            type="button"
                            class="text-sm text-emerald-700 underline"
                            @click="openRoleModal(m)"
                        >
                            {{ m.role_label }}
                        </button>
                        <span v-else class="text-sm text-slate-700">{{ m.role_label }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <button
                            v-if="!m.is_owner && permissions.canRemoveTeamMembers && authUserId !== m.id"
                            type="button"
                            class="text-sm text-rose-600 hover:underline"
                            @click="confirmRemove(m)"
                        >
                            Revoke access
                        </button>
                    </td>
                </tr>
            </AppTable>
        </AppCard>

        <div
            v-if="roleModalOpen"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4"
            @click.self="roleModalOpen = false"
        >
            <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-lg bg-white p-5 shadow-xl">
                <h4 class="text-lg font-semibold text-slate-900">Change role</h4>
                <p class="mt-1 text-sm text-slate-500">{{ roleTarget?.name }}</p>
                <div class="mt-4 space-y-2">
                    <button
                        v-for="r in available_roles"
                        :key="r.key"
                        type="button"
                        class="w-full rounded-md border px-3 py-2 text-left text-sm transition"
                        :class="updateRoleForm.role === r.key ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200 hover:bg-slate-50'"
                        @click="updateRoleForm.role = r.key"
                    >
                        <span class="font-medium">{{ r.name }}</span>
                        <span v-if="r.description" class="mt-1 block text-xs text-slate-500">{{ r.description }}</span>
                    </button>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="ghost" @click="roleModalOpen = false">Cancel</AppButton>
                    <AppButton variant="primary" :disabled="updateRoleForm.processing" @click="saveRole">Save</AppButton>
                </div>
            </div>
        </div>

        <div
            v-if="removeTarget"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4"
            @click.self="removeTarget = null"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h4 class="text-lg font-semibold text-slate-900">Remove member</h4>
                <p class="mt-2 text-sm text-slate-600">
                    Remove <strong>{{ removeTarget.name }}</strong> from this team? They will lose access immediately.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="ghost" @click="removeTarget = null">Cancel</AppButton>
                    <AppButton variant="primary" class="!bg-rose-600" :disabled="removeForm.processing" @click="removeMember">
                        Remove
                    </AppButton>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
