<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    DialogContent,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'radix-vue';
import InstanceSettingsShell from '@/Components/InstanceSettingsShell.vue';

type OwnerRow = {
    id: number;
    name: string;
    email: string;
    primary: boolean;
};

type MemberRow = {
    id: number;
    name: string;
    email: string;
    role_key: string;
    role_label: string;
};

type InvitationRow = {
    id: number;
    email: string;
    role_key: string;
    role_label: string;
};

type RoleRow = {
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

const props = defineProps<{
    team: { id: number; name: string };
    owners: OwnerRow[];
    members: MemberRow[];
    invitations: InvitationRow[];
    roles: RoleRow[];
    permission_groups: PermissionGroup[];
}>();

const ownerOpen = ref(false);
const editingOwnerId = ref<number | null>(null);
const ownerForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const openAddOwner = () => {
    editingOwnerId.value = null;
    ownerForm.reset();
    ownerForm.clearErrors();
    ownerOpen.value = true;
};

const openEditOwner = (owner: OwnerRow) => {
    editingOwnerId.value = owner.id;
    ownerForm.clearErrors();
    ownerForm.name = owner.name;
    ownerForm.email = owner.email;
    ownerForm.password = '';
    ownerForm.password_confirmation = '';
    ownerOpen.value = true;
};

const saveOwner = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            ownerOpen.value = false;
            ownerForm.reset('password', 'password_confirmation');
        },
    };

    if (editingOwnerId.value === null) {
        ownerForm.post(route('settings.instance.teams.owners.store', props.team.id), options);
        return;
    }

    ownerForm.put(route('settings.instance.teams.owners.update', [props.team.id, editingOwnerId.value]), options);
};

const removeOwner = (owner: OwnerRow) => {
    if (!window.confirm(`Remove ${owner.name} as an owner of ${props.team.name}? Their sign-in is kept.`)) {
        return;
    }

    router.delete(route('settings.instance.teams.owners.destroy', [props.team.id, owner.id]), {
        preserveScroll: true,
    });
};

const memberOpen = ref(false);
const memberForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: props.roles[0]?.key ?? 'accountant',
});

const openAddMember = () => {
    memberForm.reset();
    memberForm.clearErrors();
    memberForm.role = props.roles[0]?.key ?? 'accountant';
    memberOpen.value = true;
};

const saveMember = () => {
    memberForm.post(route('settings.instance.teams.members.store', props.team.id), {
        preserveScroll: true,
        onSuccess: () => memberOpen.value = false,
    });
};

const roleTarget = ref<MemberRow | null>(null);
const roleForm = useForm({ role: '' });

const openRole = (member: MemberRow) => {
    roleTarget.value = member;
    roleForm.role = member.role_key;
    roleForm.clearErrors();
};

const saveRole = () => {
    if (!roleTarget.value) {
        return;
    }

    roleForm.put(route('settings.instance.teams.members.update', [props.team.id, roleTarget.value.id]), {
        preserveScroll: true,
        onSuccess: () => {
            roleTarget.value = null;
        },
    });
};

const removeMember = (member: MemberRow) => {
    if (!window.confirm(`Remove ${member.name} from ${props.team.name}? Their sign-in is kept.`)) {
        return;
    }

    router.delete(route('settings.instance.teams.members.destroy', [props.team.id, member.id]), {
        preserveScroll: true,
    });
};

const inviteOpen = ref(false);
const inviteForm = useForm({
    email: '',
    role: props.roles[0]?.key ?? 'accountant',
});

const openInvite = () => {
    inviteForm.reset();
    inviteForm.clearErrors();
    inviteForm.role = props.roles[0]?.key ?? 'accountant';
    inviteOpen.value = true;
};

const sendInvite = () => {
    inviteForm.post(route('team-members.store', props.team.id), {
        errorBag: 'addTeamMember',
        preserveScroll: true,
        onSuccess: () => inviteOpen.value = false,
    });
};

const revokeInvitation = (invitation: InvitationRow) => {
    if (!window.confirm(`Revoke the invitation to ${invitation.email}?`)) {
        return;
    }

    router.delete(route('team-invitations.destroy', invitation.id), { preserveScroll: true });
};

const resendInvitation = (invitation: InvitationRow) => {
    router.post(route('team-invitations.resend', invitation.id), {}, { preserveScroll: true });
};

const roleEditorOpen = ref(false);
const editingRole = ref<RoleRow | null>(null);
const customRoleForm = useForm({
    name: '',
    description: '',
    permissions: [] as string[],
});

const openCreateRole = () => {
    editingRole.value = null;
    customRoleForm.reset();
    customRoleForm.clearErrors();
    customRoleForm.permissions = [];
    roleEditorOpen.value = true;
};

const openEditRole = (role: RoleRow) => {
    if (role.is_system) {
        return;
    }

    editingRole.value = role;
    customRoleForm.clearErrors();
    customRoleForm.name = role.name;
    customRoleForm.description = role.description ?? '';
    customRoleForm.permissions = [...role.permissions];
    roleEditorOpen.value = true;
};

const togglePermission = (key: string) => {
    if (customRoleForm.permissions.includes(key)) {
        customRoleForm.permissions = customRoleForm.permissions.filter((permission) => permission !== key);
        return;
    }

    customRoleForm.permissions = [...customRoleForm.permissions, key];
};

const saveCustomRole = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            roleEditorOpen.value = false;
            editingRole.value = null;
        },
    };

    if (editingRole.value) {
        customRoleForm.put(route('settings.team.roles.update', [props.team.id, editingRole.value.id]), options);
        return;
    }

    customRoleForm.post(route('settings.team.roles.store', props.team.id), options);
};

const deleteRole = (role: RoleRow) => {
    if (role.is_system) {
        return;
    }

    if (!window.confirm(`Delete the ${role.name} role? Members using it must be reassigned first.`)) {
        return;
    }

    router.delete(route('settings.team.roles.destroy', [props.team.id, role.id]), { preserveScroll: true });
};
</script>

<template>
    <InstanceSettingsShell
        section="teams"
        :title="`Team · ${team.name}`"
        :subtitle="`Owners, members, and roles for ${team.name}.`"
    >
        <div class="space-y-6">
            <AppCard class="overflow-hidden p-0">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-canvas-200 bg-canvas-100 px-5 py-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Owners</h3>
                        <p class="mt-0.5 max-w-2xl text-sm text-slate-500">
                            A new email creates a sign-in and emails a notice. There is nothing to accept, and the password is not in the email.
                        </p>
                    </div>
                    <AppButton variant="primary" size="sm" @click="openAddOwner">
                        Add owner
                    </AppButton>
                </div>
                <AppTable
                    embedded
                    dense
                    table-class="text-sm"
                    :columns="[
                        { key: 'name', label: 'Name' },
                        { key: 'email', label: 'Email' },
                        { key: 'actions', label: '', widthClass: 'text-right' },
                    ]"
                >
                    <tr v-for="owner in owners" :key="owner.id" class="border-t border-slate-100">
                        <td class="px-3 py-2">
                            <div class="font-medium text-slate-900">{{ owner.name }}</div>
                            <div v-if="owner.primary" class="text-xs text-slate-500">Primary</div>
                        </td>
                        <td class="px-3 py-2 text-slate-600">{{ owner.email }}</td>
                        <td class="px-3 py-2 text-right">
                            <button type="button" class="text-sm font-medium text-brand-700 hover:text-brand-800" @click="openEditOwner(owner)">
                                Edit
                            </button>
                            <button type="button" class="ml-3 text-sm font-medium text-rose-700 hover:text-rose-800" @click="removeOwner(owner)">
                                Remove
                            </button>
                        </td>
                    </tr>
                    <template v-if="owners.length === 0" #empty>
                        <EmptyState title="No owners yet" description="Add the person who should sign in and run this business." />
                    </template>
                </AppTable>
            </AppCard>

            <AppCard class="overflow-hidden p-0">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-canvas-200 bg-canvas-100 px-5 py-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Members</h3>
                        <p class="mt-0.5 max-w-2xl text-sm text-slate-500">
                            Add an accountant, viewer, or custom role without an invitation. Invite by email when they should accept a link.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <AppButton variant="secondary" size="sm" @click="openInvite">
                            Invite
                        </AppButton>
                        <AppButton variant="primary" size="sm" @click="openAddMember">
                            Add member
                        </AppButton>
                    </div>
                </div>
                <AppTable
                    embedded
                    dense
                    table-class="text-sm"
                    :columns="[
                        { key: 'name', label: 'Name' },
                        { key: 'email', label: 'Email' },
                        { key: 'role', label: 'Role' },
                        { key: 'actions', label: '', widthClass: 'text-right' },
                    ]"
                >
                    <tr v-for="member in members" :key="member.id" class="border-t border-slate-100">
                        <td class="px-3 py-2 font-medium text-slate-900">{{ member.name }}</td>
                        <td class="px-3 py-2 text-slate-600">{{ member.email }}</td>
                        <td class="px-3 py-2">
                            <button type="button" class="text-sm font-medium text-brand-700 hover:text-brand-800" @click="openRole(member)">
                                {{ member.role_label }}
                            </button>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <button type="button" class="text-sm font-medium text-rose-700 hover:text-rose-800" @click="removeMember(member)">
                                Remove
                            </button>
                        </td>
                    </tr>
                    <template v-if="members.length === 0" #empty>
                        <EmptyState title="No other members" description="Owners are listed above. Add anyone else who should work in this business." />
                    </template>
                </AppTable>
                <div v-if="invitations.length" class="border-t border-slate-200 px-5 py-4">
                    <h4 class="text-sm font-semibold text-slate-900">Pending invitations</h4>
                    <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                        <li v-for="invitation in invitations" :key="invitation.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <div class="min-w-0">
                                <span class="font-medium text-slate-900">{{ invitation.email }}</span>
                                <span class="ml-2 text-slate-500">{{ invitation.role_label }}</span>
                            </div>
                            <div class="flex shrink-0 gap-3">
                                <button type="button" class="font-medium text-brand-700 hover:text-brand-800" @click="resendInvitation(invitation)">
                                    Resend
                                </button>
                                <button type="button" class="font-medium text-rose-700 hover:text-rose-800" @click="revokeInvitation(invitation)">
                                    Revoke
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
            </AppCard>

            <AppCard class="overflow-hidden p-0">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-canvas-200 bg-canvas-100 px-5 py-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Roles</h3>
                        <p class="mt-0.5 max-w-2xl text-sm text-slate-500">
                            Accountant and Viewer are built in. Custom roles belong to this business.
                        </p>
                    </div>
                    <AppButton variant="primary" size="sm" @click="openCreateRole">
                        Create role
                    </AppButton>
                </div>
                <AppTable
                    embedded
                    dense
                    table-class="text-sm"
                    :columns="[
                        { key: 'name', label: 'Role' },
                        { key: 'permissions', label: 'Permissions', widthClass: 'whitespace-nowrap tabular-nums' },
                        { key: 'actions', label: '', widthClass: 'text-right' },
                    ]"
                >
                    <tr v-for="role in roles" :key="role.id" class="border-t border-slate-100">
                        <td class="px-3 py-2">
                            <div class="font-medium text-slate-900">{{ role.name }}</div>
                            <div class="text-xs text-slate-500">{{ role.is_system ? 'Built-in' : (role.description || 'Custom') }}</div>
                        </td>
                        <td class="px-3 py-2 text-slate-700">{{ role.permission_count }}</td>
                        <td class="px-3 py-2 text-right">
                            <template v-if="!role.is_system">
                                <button type="button" class="text-sm font-medium text-brand-700 hover:text-brand-800" @click="openEditRole(role)">
                                    Edit
                                </button>
                                <button type="button" class="ml-3 text-sm font-medium text-rose-700 hover:text-rose-800" @click="deleteRole(role)">
                                    Delete
                                </button>
                            </template>
                        </td>
                    </tr>
                </AppTable>
            </AppCard>
        </div>

        <DialogRoot v-model:open="ownerOpen">
            <DialogPortal>
                <DialogOverlay class="fixed inset-0 z-[80] bg-slate-900/40" />
                <DialogContent class="fixed left-1/2 top-1/2 z-[90] w-[92vw] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-lg border border-slate-200 bg-white p-5 shadow-xl">
                    <DialogTitle class="text-lg font-semibold text-slate-900">
                        {{ editingOwnerId === null ? 'Add owner' : 'Edit owner' }}
                    </DialogTitle>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ editingOwnerId === null ? 'They receive a notice that they own this business.' : 'Leave the password blank to keep the current one.' }}
                    </p>
                    <form class="mt-4 space-y-3" @submit.prevent="saveOwner">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="owner-name">Name</label>
                            <AppInput id="owner-name" v-model="ownerForm.name" type="text" autocomplete="off" />
                            <p v-if="ownerForm.errors.name" class="mt-1 text-xs text-rose-600">{{ ownerForm.errors.name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="owner-email">Email</label>
                            <AppInput id="owner-email" v-model="ownerForm.email" type="email" autocomplete="off" />
                            <p v-if="ownerForm.errors.email" class="mt-1 text-xs text-rose-600">{{ ownerForm.errors.email }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="owner-password">Password</label>
                            <AppInput id="owner-password" v-model="ownerForm.password" type="password" autocomplete="new-password" />
                            <p v-if="ownerForm.errors.password" class="mt-1 text-xs text-rose-600">{{ ownerForm.errors.password }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="owner-password-confirmation">Confirm password</label>
                            <AppInput id="owner-password-confirmation" v-model="ownerForm.password_confirmation" type="password" autocomplete="new-password" />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <AppButton type="button" variant="secondary" @click="ownerOpen = false">Cancel</AppButton>
                            <AppButton type="submit" variant="primary" :loading="ownerForm.processing">Save</AppButton>
                        </div>
                    </form>
                </DialogContent>
            </DialogPortal>
        </DialogRoot>

        <DialogRoot v-model:open="memberOpen">
            <DialogPortal>
                <DialogOverlay class="fixed inset-0 z-[80] bg-slate-900/40" />
                <DialogContent class="fixed left-1/2 top-1/2 z-[90] w-[92vw] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-lg border border-slate-200 bg-white p-5 shadow-xl">
                    <DialogTitle class="text-lg font-semibold text-slate-900">Add member</DialogTitle>
                    <p class="mt-1 text-sm text-slate-500">A new email creates a sign-in. No invitation is sent.</p>
                    <form class="mt-4 space-y-3" @submit.prevent="saveMember">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="member-name">Name</label>
                            <AppInput id="member-name" v-model="memberForm.name" type="text" autocomplete="off" />
                            <p v-if="memberForm.errors.name" class="mt-1 text-xs text-rose-600">{{ memberForm.errors.name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="member-email">Email</label>
                            <AppInput id="member-email" v-model="memberForm.email" type="email" autocomplete="off" />
                            <p v-if="memberForm.errors.email" class="mt-1 text-xs text-rose-600">{{ memberForm.errors.email }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="member-role">Role</label>
                            <select id="member-role" v-model="memberForm.role" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900">
                                <option v-for="role in roles" :key="role.key" :value="role.key">{{ role.name }}</option>
                            </select>
                            <p v-if="memberForm.errors.role" class="mt-1 text-xs text-rose-600">{{ memberForm.errors.role }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="member-password">Password</label>
                            <AppInput id="member-password" v-model="memberForm.password" type="password" autocomplete="new-password" />
                            <p v-if="memberForm.errors.password" class="mt-1 text-xs text-rose-600">{{ memberForm.errors.password }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="member-password-confirmation">Confirm password</label>
                            <AppInput id="member-password-confirmation" v-model="memberForm.password_confirmation" type="password" autocomplete="new-password" />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <AppButton type="button" variant="secondary" @click="memberOpen = false">Cancel</AppButton>
                            <AppButton type="submit" variant="primary" :loading="memberForm.processing">Save</AppButton>
                        </div>
                    </form>
                </DialogContent>
            </DialogPortal>
        </DialogRoot>

        <DialogRoot :open="roleTarget !== null" @update:open="(open) => { if (!open) roleTarget = null; }">
            <DialogPortal>
                <DialogOverlay class="fixed inset-0 z-[80] bg-slate-900/40" />
                <DialogContent class="fixed left-1/2 top-1/2 z-[90] w-[92vw] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-lg border border-slate-200 bg-white p-5 shadow-xl">
                    <DialogTitle class="text-lg font-semibold text-slate-900">Change role</DialogTitle>
                    <p class="mt-1 text-sm text-slate-500">{{ roleTarget?.name }}</p>
                    <form class="mt-4 space-y-3" @submit.prevent="saveRole">
                        <select v-model="roleForm.role" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900">
                            <option v-for="role in roles" :key="role.key" :value="role.key">{{ role.name }}</option>
                        </select>
                        <p v-if="roleForm.errors.role" class="text-xs text-rose-600">{{ roleForm.errors.role }}</p>
                        <div class="flex justify-end gap-3 pt-2">
                            <AppButton type="button" variant="secondary" @click="roleTarget = null">Cancel</AppButton>
                            <AppButton type="submit" variant="primary" :loading="roleForm.processing">Save</AppButton>
                        </div>
                    </form>
                </DialogContent>
            </DialogPortal>
        </DialogRoot>

        <DialogRoot v-model:open="inviteOpen">
            <DialogPortal>
                <DialogOverlay class="fixed inset-0 z-[80] bg-slate-900/40" />
                <DialogContent class="fixed left-1/2 top-1/2 z-[90] w-[92vw] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-lg border border-slate-200 bg-white p-5 shadow-xl">
                    <DialogTitle class="text-lg font-semibold text-slate-900">Invite by email</DialogTitle>
                    <p class="mt-1 text-sm text-slate-500">They get a join link and choose how to sign in.</p>
                    <form class="mt-4 space-y-3" @submit.prevent="sendInvite">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="invite-email">Email</label>
                            <AppInput id="invite-email" v-model="inviteForm.email" type="email" autocomplete="off" />
                            <p v-if="inviteForm.errors.email" class="mt-1 text-xs text-rose-600">{{ inviteForm.errors.email }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="invite-role">Role</label>
                            <select id="invite-role" v-model="inviteForm.role" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900">
                                <option v-for="role in roles" :key="role.key" :value="role.key">{{ role.name }}</option>
                            </select>
                            <p v-if="inviteForm.errors.role" class="mt-1 text-xs text-rose-600">{{ inviteForm.errors.role }}</p>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <AppButton type="button" variant="secondary" @click="inviteOpen = false">Cancel</AppButton>
                            <AppButton type="submit" variant="primary" :loading="inviteForm.processing">Send invitation</AppButton>
                        </div>
                    </form>
                </DialogContent>
            </DialogPortal>
        </DialogRoot>

        <DialogRoot v-model:open="roleEditorOpen">
            <DialogPortal>
                <DialogOverlay class="fixed inset-0 z-[80] bg-slate-900/40" />
                <DialogContent class="fixed left-1/2 top-1/2 z-[90] max-h-[90vh] w-[92vw] max-w-lg -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-lg border border-slate-200 bg-white p-5 shadow-xl">
                    <DialogTitle class="text-lg font-semibold text-slate-900">
                        {{ editingRole ? 'Edit role' : 'Create role' }}
                    </DialogTitle>
                    <form class="mt-4 space-y-4" @submit.prevent="saveCustomRole">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="role-name">Name</label>
                            <AppInput id="role-name" v-model="customRoleForm.name" type="text" />
                            <p v-if="customRoleForm.errors.name" class="mt-1 text-xs text-rose-600">{{ customRoleForm.errors.name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500" for="role-description">Description</label>
                            <AppInput id="role-description" v-model="customRoleForm.description" type="text" />
                        </div>
                        <div v-for="group in permission_groups" :key="group.name" class="space-y-2">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ group.name }}</h4>
                            <label v-for="permission in group.permissions" :key="permission.key" class="flex items-start gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    class="mt-0.5 rounded border-slate-300 text-brand-600"
                                    :checked="customRoleForm.permissions.includes(permission.key)"
                                    @change="togglePermission(permission.key)"
                                >
                                <span>{{ permission.label }}</span>
                            </label>
                        </div>
                        <p v-if="customRoleForm.errors.permissions" class="text-xs text-rose-600">{{ customRoleForm.errors.permissions }}</p>
                        <div class="flex justify-end gap-3 pt-2">
                            <AppButton type="button" variant="secondary" @click="roleEditorOpen = false">Cancel</AppButton>
                            <AppButton type="submit" variant="primary" :loading="customRoleForm.processing">Save</AppButton>
                        </div>
                    </form>
                </DialogContent>
            </DialogPortal>
        </DialogRoot>
    </InstanceSettingsShell>
</template>
