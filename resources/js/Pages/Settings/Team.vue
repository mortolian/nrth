<script setup lang="ts">
import { computed, ref, watch, withDefaults } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
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

const props = withDefaults(
    defineProps<{
        team: {
            id: number;
            name: string;
            personal_team: boolean;
            owner: { name: string; email: string; profile_photo_url: string } | null;
        };
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
        team_settings_entry?: 'settings' | 'direct';
    }>(),
    { team_settings_entry: 'settings' },
);

const breadcrumbs = computed(() => {
    if (props.team_settings_entry === 'direct') {
        return [
            { label: 'Account', href: route('profile.show') },
            { label: props.team.name },
        ];
    }
    return [
        { label: 'Settings', href: route('profile.show') },
        { label: 'Teams and members' },
    ];
});

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
const leaveForm = useForm({});
const leaveModalOpen = ref(false);

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

const leaveTeam = () => {
    if (!authUserId.value) return;
    leaveForm.delete(route('team-members.destroy', [props.team.id, authUserId.value]), {
        preserveScroll: true,
        onSuccess: () => {
            leaveModalOpen.value = false;
        },
    });
};

const updateTeamNameForm = useForm({ name: props.team.name });
watch(
    () => props.team.name,
    (name) => {
        updateTeamNameForm.name = name;
    },
);

const submitTeamName = () => {
    updateTeamNameForm.put(route('teams.update', props.team.id), {
        errorBag: 'updateTeamName',
        preserveScroll: true,
    });
};

const deleteTeamForm = useForm({});
const deleteTeamModalOpen = ref(false);

const deleteTeam = () => {
    deleteTeamForm.delete(route('teams.destroy', props.team.id), {
        errorBag: 'deleteTeam',
    });
};
</script>

<template>
    <AppLayout title="Teams and members" :breadcrumbs="breadcrumbs">
        <PageHeader
            title="Teams and members"
            :subtitle="`Workspace “${team.name}”: invite people, assign roles, and control who can use billing and invoicing with you.`"
        />

        <div class="mt-5 space-y-6">
            <AppCard>
                <h3 class="text-base font-semibold text-slate-900">Teams and members</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-slate-500">
                    Same layout as Company settings—grouped sections with short descriptions. The owner always has full access; other members follow the role you assign.
                </p>

                <div class="mt-6 space-y-5">
                    <section v-if="team.owner" class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Workspace &amp; owner</h4>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Display name for this team and the person who owns the workspace (billing and full control).
                        </p>
                        <div class="mt-4 flex items-center gap-3 rounded-lg border border-slate-200/90 bg-white px-3 py-3">
                            <img
                                :src="team.owner.profile_photo_url"
                                :alt="team.owner.name"
                                class="h-12 w-12 shrink-0 rounded-full object-cover"
                            >
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-slate-900">{{ team.owner.name }}</div>
                                <div class="truncate text-xs text-slate-500">{{ team.owner.email }}</div>
                                <div class="text-xs text-slate-400">Team owner</div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Team name</label>
                            <AppInput
                                v-model="updateTeamNameForm.name"
                                type="text"
                                class="max-w-md"
                                :disabled="!permissions.canUpdateTeam"
                            />
                            <p v-if="updateTeamNameForm.errors.name" class="mt-1 text-xs text-rose-600">
                                {{ updateTeamNameForm.errors.name }}
                            </p>
                        </div>
                        <div v-if="permissions.canUpdateTeam" class="mt-4 flex flex-wrap items-center gap-3">
                            <AppButton variant="primary" :disabled="updateTeamNameForm.processing" @click="submitTeamName">
                                Save name
                            </AppButton>
                            <span v-if="updateTeamNameForm.recentlySuccessful" class="text-sm text-brand-600">Saved.</span>
                        </div>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Roles on this team</h4>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Each role limits what teammates can see and change. Pick the smallest role that still lets someone do their job.
                        </p>
                        <div class="mt-4 grid gap-3 md:grid-cols-3">
                            <div
                                v-for="summary in role_summaries"
                                :key="summary.key"
                                class="rounded-lg border border-slate-200/90 bg-white p-4 shadow-sm"
                            >
                                <h5 class="text-sm font-semibold text-slate-900">{{ summary.title }}</h5>
                                <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ summary.description }}</p>
                            </div>
                        </div>
                    </section>

                    <section v-if="permissions.canAddTeamMembers" class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Invite by email</h4>
                        <p class="mt-0.5 text-xs text-slate-500">
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
                            <AppButton variant="primary" :disabled="inviteForm.processing" @click="submitInvite">
                                Send invitation
                            </AppButton>
                        </div>
                    </section>

                    <section
                        v-if="invitations.length && permissions.canAddTeamMembers"
                        class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5"
                    >
                        <h4 class="text-sm font-semibold text-slate-900">Pending invitations</h4>
                        <p class="mt-0.5 text-xs text-slate-500">People who have been invited but have not joined yet.</p>
                        <ul class="mt-4 divide-y divide-slate-200/80 rounded-lg border border-slate-200/90 bg-white">
                            <li v-for="inv in invitations" :key="inv.id" class="flex items-center justify-between gap-3 px-3 py-3 text-sm first:rounded-t-lg last:rounded-b-lg">
                                <div class="min-w-0">
                                    <span class="font-medium text-slate-900">{{ inv.email }}</span>
                                    <span class="ml-2 text-slate-500">({{ inv.role_label }})</span>
                                </div>
                                <button
                                    v-if="permissions.canRemoveTeamMembers"
                                    type="button"
                                    class="shrink-0 text-rose-600 hover:underline"
                                    @click="cancelInvitation(inv)"
                                >
                                    Revoke
                                </button>
                            </li>
                        </ul>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Team members</h4>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Everyone with access to this workspace. Owners cannot be removed; other roles can be changed or revoked if you have permission.
                        </p>
                        <div class="mt-4 overflow-hidden rounded-lg border border-slate-200/90 bg-white">
                            <AppTable
                                :columns="[
                                    { key: 'member', label: 'Member' },
                                    { key: 'role', label: 'Role' },
                                    { key: 'actions', label: '' },
                                ]"
                                :page="1"
                                :last-page="1"
                            >
                                <tr v-for="m in members" :key="m.id" class="border-b border-slate-100 last:border-b-0">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <img :src="m.profile_photo_url" :alt="m.name" class="h-9 w-9 rounded-full object-cover">
                                            <div class="min-w-0">
                                                <div class="font-medium text-slate-900">{{ m.name }}</div>
                                                <div class="truncate text-xs text-slate-500">{{ m.email }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <button
                                            v-if="permissions.canUpdateTeamMembers && !m.is_owner && available_roles.length"
                                            type="button"
                                            class="text-sm text-brand-700 underline"
                                            @click="openRoleModal(m)"
                                        >
                                            {{ m.role_label }}
                                        </button>
                                        <span v-else class="text-sm text-slate-700">{{ m.role_label }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            v-if="authUserId === m.id && !m.is_owner"
                                            type="button"
                                            class="text-sm text-rose-600 hover:underline"
                                            @click="leaveModalOpen = true"
                                        >
                                            Leave team
                                        </button>
                                        <button
                                            v-else-if="!m.is_owner && permissions.canRemoveTeamMembers && authUserId !== m.id"
                                            type="button"
                                            class="text-sm text-rose-600 hover:underline"
                                            @click="confirmRemove(m)"
                                        >
                                            Revoke access
                                        </button>
                                    </td>
                                </tr>
                            </AppTable>
                        </div>
                    </section>

                    <section
                        v-if="permissions.canDeleteTeam && !team.personal_team"
                        class="rounded-xl border border-rose-200 bg-rose-50/50 p-4 md:p-5"
                    >
                        <h4 class="text-sm font-semibold text-slate-900">Delete team</h4>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Permanently delete this team and its data. Download anything you need to keep first.
                        </p>
                        <div class="mt-4">
                            <AppButton
                                variant="ghost"
                                class="!border-rose-200 !text-rose-700 hover:!bg-rose-50"
                                @click="deleteTeamModalOpen = true"
                            >
                                Delete team…
                            </AppButton>
                        </div>
                    </section>
                </div>
            </AppCard>
        </div>

        <div
            v-if="deleteTeamModalOpen"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4"
            @click.self="deleteTeamModalOpen = false"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h4 class="text-lg font-semibold text-slate-900">Delete team</h4>
                <p class="mt-2 text-sm text-slate-600">
                    Are you sure? This cannot be undone. All resources for this team will be permanently removed.
                </p>
                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="ghost" @click="deleteTeamModalOpen = false">Cancel</AppButton>
                    <AppButton variant="primary" class="!bg-rose-600" :disabled="deleteTeamForm.processing" @click="deleteTeam">
                        Delete team
                    </AppButton>
                </div>
            </div>
        </div>

        <div
            v-if="leaveModalOpen"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4"
            @click.self="leaveModalOpen = false"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h4 class="text-lg font-semibold text-slate-900">Leave team</h4>
                <p class="mt-2 text-sm text-slate-600">Are you sure you want to leave this team? You will lose access immediately.</p>
                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="ghost" @click="leaveModalOpen = false">Cancel</AppButton>
                    <AppButton variant="primary" class="!bg-rose-600" :disabled="leaveForm.processing" @click="leaveTeam">
                        Leave
                    </AppButton>
                </div>
            </div>
        </div>

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
                        :class="updateRoleForm.role === r.key ? 'border-brand-500 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'"
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
