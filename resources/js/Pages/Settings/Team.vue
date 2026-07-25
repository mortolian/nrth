<script setup lang="ts">
import { computed, ref, watch, withDefaults } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';

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

type TeamRoleOption = {
    id: number;
    key: string;
    name: string;
    description?: string | null;
    is_system: boolean;
    permissions: string[];
    permission_count: number;
};

type PermissionGroup = {
    name: string;
    permissions: Array<{ key: string; label: string }>;
};

type RoleSummary = {
    key: string;
    title: string;
    description: string;
    is_system?: boolean;
    permission_count?: number;
    id?: number;
};

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
        available_roles: TeamRoleOption[];
        permission_groups: PermissionGroup[];
        permissions: {
            canAddTeamMembers: boolean;
            canDeleteTeam: boolean;
            canRemoveTeamMembers: boolean;
            canUpdateTeam: boolean;
            canUpdateTeamMembers: boolean;
            canManageRoles?: boolean;
        };
        role_summaries: RoleSummary[];
        team_settings_entry?: 'settings' | 'direct';
        session_idle_timeout_minutes?: number;
        session_lifetime_minutes?: number;
    }>(),
    {
        team_settings_entry: 'settings',
        session_idle_timeout_minutes: 0,
        session_lifetime_minutes: 120,
        permission_groups: () => [],
    },
);

const page = usePage();
const authUserId = computed(() => (page.props.auth as { user?: { id: number } })?.user?.id);
const canManageRoles = computed(() => Boolean(props.permissions.canManageRoles ?? props.permissions.canUpdateTeam));

const teamSubtitle = computed(
    () =>
        `People who can access the currently selected business “${props.team.name}”. Invite members and assign roles for this business only.`,
);

const idleTimeoutForm = useForm({
    session_idle_timeout_minutes: String(Number(props.session_idle_timeout_minutes ?? 0)),
});

watch(
    () => props.session_idle_timeout_minutes,
    (value) => {
        idleTimeoutForm.session_idle_timeout_minutes = String(Number(value ?? 0));
    },
);

const idleTimeoutOptions = computed(() => {
    const max = Math.max(0, Number(props.session_lifetime_minutes ?? 120));
    const presets = [
        { label: 'Off', value: '0' },
        { label: '15 minutes', value: '15' },
        { label: '30 minutes', value: '30' },
        { label: '60 minutes', value: '60' },
        { label: '120 minutes', value: '120' },
    ];

    return presets.filter((option) => Number(option.value) <= max);
});

const saveIdleTimeout = () => {
    idleTimeoutForm
        .transform((data) => ({
            session_idle_timeout_minutes: Number(data.session_idle_timeout_minutes),
        }))
        .put(route('settings.team.session-idle-timeout'), {
            preserveScroll: true,
        });
};

const inviteForm = useForm({
    email: '',
    role: props.available_roles[0]?.key ?? 'accountant',
});

watch(
    () => props.available_roles,
    (roles) => {
        if (!roles.some((r) => r.key === inviteForm.role)) {
            inviteForm.role = roles[0]?.key ?? 'accountant';
        }
    },
);

const submitInvite = () => {
    inviteForm.post(route('team-members.store', props.team.id), {
        errorBag: 'addTeamMember',
        preserveScroll: true,
        onSuccess: () => inviteForm.reset('email'),
    });
};

const cancelInvitation = (invitation: Invitation) => {
    if (
        !window.confirm(
            `Revoke the invitation to ${invitation.email}? They will not be able to join using the current link.`,
        )
    ) {
        return;
    }
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

const roleEditorOpen = ref(false);
const editingRole = ref<TeamRoleOption | null>(null);
const roleForm = useForm({
    name: '',
    description: '',
    permissions: [] as string[],
});

const openCreateRole = () => {
    editingRole.value = null;
    roleForm.name = '';
    roleForm.description = '';
    roleForm.permissions = [];
    roleForm.clearErrors();
    roleEditorOpen.value = true;
};

const openEditRole = (summary: RoleSummary) => {
    const role = props.available_roles.find((r) => r.key === summary.key);
    if (!role || role.is_system) return;
    editingRole.value = role;
    roleForm.name = role.name;
    roleForm.description = role.description ?? '';
    roleForm.permissions = [...role.permissions];
    roleForm.clearErrors();
    roleEditorOpen.value = true;
};

const togglePermission = (key: string) => {
    if (roleForm.permissions.includes(key)) {
        roleForm.permissions = roleForm.permissions.filter((p) => p !== key);
    } else {
        roleForm.permissions = [...roleForm.permissions, key];
    }
};

const saveCustomRole = () => {
    if (editingRole.value) {
        roleForm.put(route('settings.team.roles.update', editingRole.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                roleEditorOpen.value = false;
                editingRole.value = null;
            },
        });
        return;
    }

    roleForm.post(route('settings.team.roles.store'), {
        preserveScroll: true,
        onSuccess: () => {
            roleEditorOpen.value = false;
        },
    });
};

const deleteCustomRole = (summary: RoleSummary) => {
    if (!summary.id) return;
    if (!window.confirm(`Delete role “${summary.title}”? Members using it must be reassigned first.`)) {
        return;
    }
    router.delete(route('settings.team.roles.destroy', summary.id), { preserveScroll: true });
};
</script>

<template>
    <SettingsShell section="team" :subtitle="teamSubtitle">
        <div class="space-y-6">
            <AppCard>
                <h3 class="text-base font-semibold text-slate-900">Team members</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-slate-500">
                    Same layout as Business settings—grouped sections with short descriptions. The owner always has full access; other members follow the role you assign.
                </p>

                <div class="mt-6 space-y-5">
                    <section v-if="team.owner" class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Business &amp; owner</h4>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Display name for this business and the person who owns it (billing and full control).
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
                                <div class="text-xs text-slate-400">Business owner</div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Business name</label>
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
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h4 class="text-sm font-semibold text-slate-900">Roles on this team</h4>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Built-in roles plus custom presets you define from the permission catalog.
                                </p>
                            </div>
                            <AppButton
                                v-if="canManageRoles"
                                variant="secondary"
                                size="sm"
                                @click="openCreateRole"
                            >
                                Create role
                            </AppButton>
                        </div>
                        <div class="mt-4 grid gap-3 md:grid-cols-3">
                            <div
                                v-for="summary in role_summaries"
                                :key="summary.key"
                                class="rounded-lg border border-slate-200/90 bg-white p-4 shadow-sm"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <h5 class="text-sm font-semibold text-slate-900">{{ summary.title }}</h5>
                                    <span
                                        v-if="!summary.is_system"
                                        class="shrink-0 text-[10px] font-medium uppercase tracking-wide text-slate-400"
                                    >
                                        Custom
                                    </span>
                                </div>
                                <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ summary.description }}</p>
                                <p v-if="summary.permission_count != null" class="mt-2 text-xs text-slate-400">
                                    {{ summary.permission_count }} permissions
                                </p>
                                <div
                                    v-if="canManageRoles && !summary.is_system && summary.key !== 'owner'"
                                    class="mt-3 flex flex-wrap gap-3"
                                >
                                    <button type="button" class="text-xs font-medium text-brand-700 hover:underline" @click="openEditRole(summary)">
                                        Edit
                                    </button>
                                    <button type="button" class="text-xs font-medium text-rose-600 hover:underline" @click="deleteCustomRole(summary)">
                                        Delete
                                    </button>
                                </div>
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
                        v-if="permissions.canUpdateTeam"
                        class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5"
                    >
                        <h4 class="text-sm font-semibold text-slate-900">Session policy</h4>
                        <p class="mt-0.5 text-xs text-slate-500">
                            How long people signed into this business can stay idle before they are signed out. Off uses only the server session lifetime ({{ session_lifetime_minutes }} minutes).
                        </p>
                        <div class="mt-4 max-w-md">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Idle session timeout</label>
                            <AppSelect
                                v-model="idleTimeoutForm.session_idle_timeout_minutes"
                                :options="idleTimeoutOptions"
                            />
                            <p class="mt-2 text-xs text-slate-500">
                                After this period with no activity, users are signed out automatically. The maximum is the server session lifetime.
                            </p>
                            <p v-if="idleTimeoutForm.errors.session_idle_timeout_minutes" class="mt-1 text-xs text-rose-600">
                                {{ idleTimeoutForm.errors.session_idle_timeout_minutes }}
                            </p>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <AppButton
                                variant="primary"
                                :disabled="idleTimeoutForm.processing"
                                @click="saveIdleTimeout"
                            >
                                Save timeout
                            </AppButton>
                            <span v-if="idleTimeoutForm.recentlySuccessful" class="text-sm text-brand-600">Saved.</span>
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
            v-if="roleEditorOpen"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4"
            @click.self="roleEditorOpen = false"
        >
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg bg-white p-5 shadow-xl">
                <h4 class="text-lg font-semibold text-slate-900">
                    {{ editingRole ? 'Edit role' : 'Create role' }}
                </h4>
                <p class="mt-1 text-sm text-slate-500">
                    Choose a name and the permissions this role should include.
                </p>
                <div class="mt-4 space-y-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Name</label>
                        <AppInput v-model="roleForm.name" type="text" placeholder="Bookkeeper" />
                        <p v-if="roleForm.errors.name" class="mt-1 text-xs text-rose-600">{{ roleForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Description</label>
                        <AppInput v-model="roleForm.description" type="text" placeholder="Optional short summary" />
                        <p v-if="roleForm.errors.description" class="mt-1 text-xs text-rose-600">{{ roleForm.errors.description }}</p>
                    </div>
                    <div class="space-y-4">
                        <div v-for="group in permission_groups" :key="group.name">
                            <h5 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ group.name }}</h5>
                            <div class="space-y-2 rounded-lg border border-slate-200 bg-slate-50/50 p-3">
                                <label
                                    v-for="perm in group.permissions"
                                    :key="perm.key"
                                    class="flex cursor-pointer items-start gap-2 text-sm text-slate-700"
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                        :checked="roleForm.permissions.includes(perm.key)"
                                        @change="togglePermission(perm.key)"
                                    >
                                    <span>{{ perm.label }}</span>
                                </label>
                            </div>
                        </div>
                        <p v-if="roleForm.errors.permissions" class="text-xs text-rose-600">{{ roleForm.errors.permissions }}</p>
                        <p v-if="roleForm.errors.role" class="text-xs text-rose-600">{{ roleForm.errors.role }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <AppButton variant="ghost" @click="roleEditorOpen = false">Cancel</AppButton>
                    <AppButton variant="primary" :disabled="roleForm.processing" @click="saveCustomRole">
                        {{ editingRole ? 'Save role' : 'Create role' }}
                    </AppButton>
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
    </SettingsShell>
</template>
