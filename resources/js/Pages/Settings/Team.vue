<script setup lang="ts">
import { Check, Copy } from 'lucide-vue-next';
import { computed, ref, watch, withDefaults } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';
import InstanceSettingsShell from '@/Components/InstanceSettingsShell.vue';
import AppTabs from '@/Components/AppTabs.vue';
import { businessSettingsTabs } from '@/Composables/useBusinessSettingsTabs';
import { useToast } from '@/Composables/useToast';

const toast = useToast();

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
        settings_context?: 'business' | 'instance';
        team_settings_entry?: 'settings' | 'direct';
        session_idle_timeout_minutes?: number;
        session_lifetime_minutes?: number;
    }>(),
    {
        settings_context: 'business',
        team_settings_entry: 'settings',
        session_idle_timeout_minutes: 0,
        session_lifetime_minutes: 120,
        permission_groups: () => [],
    },
);

const page = usePage();
const authUserId = computed(() => (page.props.auth as { user?: { id: number } })?.user?.id);
const teamPermissions = computed(() => {
    const perms = page.props.team_permissions;
    return Array.isArray(perms) ? (perms as string[]) : [];
});
const settingsContext = computed(() => props.settings_context ?? 'business');
const isBusinessContext = computed(() => settingsContext.value === 'business');
const businessTabs = computed(() => businessSettingsTabs({
    linkAll: true,
    teamPermissions: teamPermissions.value,
}));
const canManageRoles = computed(() => Boolean(props.permissions.canManageRoles ?? props.permissions.canUpdateTeam));

const teamSubtitle = computed(() => {
    if (isBusinessContext.value) {
        return `People who can access “${props.team.name}”. Invite members and assign roles for this business.`;
    }

    return `Manage members and roles for “${props.team.name}”.`;
});

const repairLedgerDryRunCommand = computed(
    () => `php artisan invoicing:repair-foreign-ledger --team=${props.team.id}`,
);
const repairLedgerApplyCommand = computed(
    () => `php artisan invoicing:repair-foreign-ledger --team=${props.team.id} --apply`,
);

const copiedOperatorCommand = ref<string | null>(null);

const copyOperatorCommand = async (command: string) => {
    try {
        await navigator.clipboard.writeText(command);
        copiedOperatorCommand.value = command;
        toast.success('Command copied.');
        window.setTimeout(() => {
            if (copiedOperatorCommand.value === command) {
                copiedOperatorCommand.value = null;
            }
        }, 2000);
    } catch {
        toast.error('Could not copy to clipboard.');
    }
};

const shellComponent = computed(() => (isBusinessContext.value ? SettingsShell : InstanceSettingsShell));

const shellBind = computed(() => (
    isBusinessContext.value
        ? { section: 'business' as const, subtitle: teamSubtitle.value }
        : {
            section: 'teams' as const,
            title: `Teams · ${props.team.name}`,
            subtitle: teamSubtitle.value,
        }
));

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
        .put(route('settings.team.session-idle-timeout', props.team.id), {
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

const resendingInvitationId = ref<number | null>(null);

const resendInvitation = (invitation: Invitation) => {
    resendingInvitationId.value = invitation.id;
    router.post(
        route('team-invitations.resend', invitation.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                resendingInvitationId.value = null;
            },
        },
    );
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
            toast.success('Member role updated.');
        },
        onError: () => {
            if (!updateRoleForm.hasErrors) {
                toast.error('Could not update member role.');
            }
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
        onSuccess: () => toast.success('Business name saved.'),
        onError: () => {
            if (!updateTeamNameForm.hasErrors) {
                toast.error('Could not save the business name.');
            }
        },
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
        roleForm.put(route('settings.team.roles.update', [props.team.id, editingRole.value.id]), {
            preserveScroll: true,
            onSuccess: () => {
                roleEditorOpen.value = false;
                editingRole.value = null;
            },
        });
        return;
    }

    roleForm.post(route('settings.team.roles.store', props.team.id), {
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
    router.delete(route('settings.team.roles.destroy', [props.team.id, summary.id]), { preserveScroll: true });
};
</script>

<template>
    <component :is="shellComponent" v-bind="shellBind">
        <div v-if="isBusinessContext" class="border-b border-slate-200">
            <AppTabs
                model-value="team_members"
                :tabs="businessTabs"
                aria-label="Business settings"
            />
        </div>

        <div class="mt-6 space-y-6">
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
                        <div v-if="permissions.canUpdateTeam" class="mt-4">
                            <FormActions class="!mt-0">
                                <AppButton
                                    variant="primary"
                                    :loading="updateTeamNameForm.processing"
                                    @click="submitTeamName"
                                >
                                    {{ updateTeamNameForm.processing ? 'Saving…' : 'Save name' }}
                                </AppButton>
                            </FormActions>
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
                            <FormActions class="!mt-0">
                                <AppButton
                                    variant="primary"
                                    :loading="inviteForm.processing"
                                    @click="submitInvite"
                                >
                                    {{ inviteForm.processing ? 'Sending…' : 'Send invitation' }}
                                </AppButton>
                            </FormActions>
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
                                <div class="flex shrink-0 items-center gap-3">
                                    <button
                                        v-if="permissions.canAddTeamMembers"
                                        type="button"
                                        class="text-brand-700 hover:underline disabled:opacity-50"
                                        :disabled="resendingInvitationId === inv.id"
                                        @click="resendInvitation(inv)"
                                    >
                                        {{ resendingInvitationId === inv.id ? 'Sending…' : 'Resend' }}
                                    </button>
                                    <button
                                        v-if="permissions.canRemoveTeamMembers"
                                        type="button"
                                        class="text-rose-600 hover:underline"
                                        @click="cancelInvitation(inv)"
                                    >
                                        Revoke
                                    </button>
                                </div>
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
                                embedded
                                dense
                                table-class="text-sm"
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
                        <div class="mt-4">
                            <FormActions class="!mt-0">
                                <AppButton
                                    variant="primary"
                                    :loading="idleTimeoutForm.processing"
                                    @click="saveIdleTimeout"
                                >
                                    {{ idleTimeoutForm.processing ? 'Saving…' : 'Save timeout' }}
                                </AppButton>
                            </FormActions>
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

            <AppCard v-if="!isBusinessContext">
                <h3 class="text-base font-semibold text-slate-900">Operator commands</h3>
                <p class="mt-1 max-w-2xl text-sm text-slate-500">
                    Run these on the server shell for this business. Use the business ID with
                    <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs text-slate-700">--team=</code>.
                </p>

                <dl class="mt-4 grid gap-1 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm sm:grid-cols-[auto_1fr] sm:items-center sm:gap-x-4">
                    <dt class="text-slate-500">Business ID</dt>
                    <dd class="font-mono tabular-nums font-medium text-slate-900">{{ team.id }}</dd>
                </dl>

                <div class="mt-5 space-y-5">
                    <div>
                        <h4 class="text-sm font-medium text-slate-900">Repair foreign invoice ledger</h4>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Dry-run first — reports drift without writing to the ledger.
                        </p>
                        <div class="mt-2 flex items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 pl-3 pr-1">
                            <pre class="min-w-0 flex-1 overflow-x-auto py-2.5 font-mono text-xs leading-relaxed text-slate-800">{{ repairLedgerDryRunCommand }}</pre>
                            <AppButton
                                variant="ghost"
                                size="sm"
                                class="shrink-0 !px-2"
                                :aria-label="copiedOperatorCommand === repairLedgerDryRunCommand ? 'Copied' : 'Copy command'"
                                @click="copyOperatorCommand(repairLedgerDryRunCommand)"
                            >
                                <Check v-if="copiedOperatorCommand === repairLedgerDryRunCommand" class="h-4 w-4" aria-hidden="true" />
                                <Copy v-else class="h-4 w-4" aria-hidden="true" />
                            </AppButton>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-sm font-medium text-slate-900">Apply ledger repair</h4>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Voids incorrect journals and rebuilds from invoice and payment rows.
                        </p>
                        <div class="mt-2 flex items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 pl-3 pr-1">
                            <pre class="min-w-0 flex-1 overflow-x-auto py-2.5 font-mono text-xs leading-relaxed text-slate-800">{{ repairLedgerApplyCommand }}</pre>
                            <AppButton
                                variant="ghost"
                                size="sm"
                                class="shrink-0 !px-2"
                                :aria-label="copiedOperatorCommand === repairLedgerApplyCommand ? 'Copied' : 'Copy command'"
                                @click="copyOperatorCommand(repairLedgerApplyCommand)"
                            >
                                <Check v-if="copiedOperatorCommand === repairLedgerApplyCommand" class="h-4 w-4" aria-hidden="true" />
                                <Copy v-else class="h-4 w-4" aria-hidden="true" />
                            </AppButton>
                        </div>
                    </div>
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
                    <AppButton
                        variant="primary"
                        class="!bg-rose-600"
                        :loading="deleteTeamForm.processing"
                        @click="deleteTeam"
                    >
                        {{ deleteTeamForm.processing ? 'Deleting…' : 'Delete team' }}
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
                    <AppButton
                        variant="primary"
                        class="!bg-rose-600"
                        :loading="leaveForm.processing"
                        @click="leaveTeam"
                    >
                        {{ leaveForm.processing ? 'Leaving…' : 'Leave' }}
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
                    <AppButton
                        variant="primary"
                        :loading="updateRoleForm.processing"
                        @click="saveRole"
                    >
                        {{ updateRoleForm.processing ? 'Saving…' : 'Save' }}
                    </AppButton>
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
                    <AppButton variant="primary" :loading="roleForm.processing" @click="saveCustomRole">
                        {{
                            roleForm.processing
                                ? editingRole
                                    ? 'Saving…'
                                    : 'Creating…'
                                : editingRole
                                    ? 'Save role'
                                    : 'Create role'
                        }}
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
                    <AppButton
                        variant="primary"
                        class="!bg-rose-600"
                        :loading="removeForm.processing"
                        @click="removeMember"
                    >
                        {{ removeForm.processing ? 'Removing…' : 'Remove' }}
                    </AppButton>
                </div>
            </div>
        </div>
    </component>
</template>
