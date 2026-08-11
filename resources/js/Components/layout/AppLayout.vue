<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Bell,
    BookOpen,
    Briefcase,
    Building2,
    Calculator,
    Car,
    ChartColumnBig,
    ChevronDown,
    ChevronRight,
    CreditCard,
    FileText,
    FolderKanban,
    Home,
    Landmark,
    LogOut,
    Menu,
    MoreHorizontal,
    PanelLeft,
    PiggyBank,
    Plus,
    Receipt,
    Search,
    Settings,
    Wallet,
    X,
} from 'lucide-vue-next';
import ApplicationMark from '@/Components/ApplicationMark.vue';
import AppButton from '@/Components/AppButton.vue';
import ToastHost from '@/Components/ToastHost.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import CommandPalette from '@/Components/layout/CommandPalette.vue';
import SessionIdleWatcher from '@/Components/layout/SessionIdleWatcher.vue';
import { useAppDisplayName } from '@/lib/appName';

const SETTINGS_SECTION_LABEL = 'Settings';

type Breadcrumb = { label: string; href?: string };
type MenuItem = { label: string; href: string; icon: unknown; matchPrefixes?: string[] };
type PaletteData = {
    quickActions?: Array<{ id: string; label: string; href?: string; icon?: 'invoice' | 'expense' | 'payment' | 'client' }>;
    navigation?: Array<{ id: string; label: string; href?: string }>;
    recent?: {
        invoices?: Array<{ id: string | number; label: string; subtitle?: string; href?: string }>;
        clients?: Array<{ id: string | number; label: string; subtitle?: string; href?: string }>;
        transactions?: Array<{ id: string | number; label: string; subtitle?: string; href?: string }>;
    };
};

const props = defineProps<{
    title?: string;
    breadcrumbs?: Breadcrumb[];
}>();

const page = usePage();
const collapsed = ref(false);
const mobileOpen = ref(false);
const quickAddOpen = ref(false);
const commandPaletteOpen = ref(false);

const appDisplayName = useAppDisplayName();

const currentTeam = computed(() => page.props.auth?.user?.current_team);
const teams = computed(() => page.props.auth?.user?.all_teams ?? []);
const authUser = computed(() => page.props.auth?.user);
const currentTeamRoleLabel = computed(() => {
    const role = page.props.current_team_role as { key?: string; label?: string } | null | undefined;
    return typeof role?.label === 'string' && role.label.trim() !== '' ? role.label : null;
});
const hasTeamFeatures = computed(() => Boolean(page.props.jetstream?.hasTeamFeatures));
const currentPath = computed(() => page.url.split('?')[0]);
const vatEnabled = computed(() => Boolean(page.props.vat_enabled));
const enabledModules = computed(() => {
    const modules = page.props.enabled_modules;
    return Array.isArray(modules) ? (modules as string[]) : [];
});
const moduleEnabled = (name: string) => enabledModules.value.includes(name);
const canAccessBackupsExports = computed(() => Boolean(page.props.can_access_backups_exports));
const canLeaveCurrentTeam = computed(() => Boolean(page.props.can_leave_current_team));
const teamPermissions = computed(() => {
    const perms = page.props.team_permissions;
    return Array.isArray(perms) ? (perms as string[]) : [];
});
const canTeam = (permission: string) => teamPermissions.value.includes(permission);

const navItems = computed<MenuItem[]>(() => {
    const items: MenuItem[] = [
        { label: 'Dashboard', href: route('dashboard'), icon: Home, matchPrefixes: ['/dashboard'] },
    ];

    const moneyInLanding = canTeam('invoices.view')
        ? route('invoicing.invoices.index')
        : canTeam('estimates.view')
            ? route('invoicing.estimates.index')
            : canTeam('clients.view')
                ? route('invoicing.clients.index')
                : null;
    if (moneyInLanding) {
        items.push({
            label: 'Money In',
            href: moneyInLanding,
            icon: Wallet,
            matchPrefixes: ['/invoicing'],
        });
    }

    const moneyOutLanding = canTeam('expenses.view')
        ? route('expenses.index')
        : canTeam('suppliers.view')
            ? route('suppliers.index')
            : null;
    if (moneyOutLanding) {
        items.push({
            label: 'Money Out',
            href: moneyOutLanding,
            icon: Landmark,
            matchPrefixes: ['/expenses', '/suppliers'],
        });
    }

    if (canTeam('banking.view')) {
        items.push({
            label: 'Banking',
            href: route('banking.transactions.index'),
            icon: Building2,
            matchPrefixes: ['/banking'],
        });
    }

    if (canTeam('accounting.view')) {
        items.push({
            label: 'Accounting',
            href: route('accounting.transactions.index'),
            icon: BookOpen,
            matchPrefixes: ['/accounting'],
        });
    }

    if (canTeam('vehicles.view')) {
        items.push({
            label: 'Travel',
            href: route('vehicles.trips.index'),
            icon: Car,
            matchPrefixes: ['/vehicles'],
        });
    }

    if (canTeam('budgets.view')) {
        items.push({
            label: 'Planning',
            href: route('budgeting.index'),
            icon: FolderKanban,
            matchPrefixes: ['/budgeting'],
        });
    }

    if (canTeam('contracts.view')) {
        items.push({
            label: 'Contracting',
            href: route('contracting.contracts.index'),
            icon: Briefcase,
            matchPrefixes: ['/contracting'],
        });
    }

    if (moduleEnabled('wealth') && canTeam('wealth.view')) {
        items.push({
            label: 'Wealth',
            href: route('wealth.index'),
            icon: PiggyBank,
            matchPrefixes: ['/wealth'],
        });
    }

    if (vatEnabled.value && canTeam('tax.view')) {
        items.push({
            label: 'Tax',
            href: route('tax.vat.index'),
            icon: Calculator,
            matchPrefixes: ['/tax'],
        });
    }

    if (vatEnabled.value && canTeam('reports.view')) {
        items.push({
            label: 'Reports',
            href: route('reports.profit-loss'),
            icon: ChartColumnBig,
            matchPrefixes: ['/reports'],
        });
    }

    if (canAccessBackupsExports.value) {
        items.push({
            label: 'Backups & exports',
            href: route('backups-exports.index'),
            icon: Archive,
            matchPrefixes: ['/backups-exports'],
        });
    }

    return items;
});

const isActivePath = (href: string) => {
    if (!href || href === '#') {
        return false;
    }

    return currentPath.value === hrefToPath(href);
};

/** Ziggy often returns absolute URLs; Inertia `page.url` is path-only. */
function hrefToPath(href: string): string {
    const withoutHash = href.split('#')[0] ?? href;
    try {
        if (/^https?:\/\//i.test(withoutHash)) {
            return new URL(withoutHash).pathname;
        }
    } catch {
        /* fall through */
    }

    return withoutHash.split('?')[0] || '/';
}

function pathMatchesPrefix(prefix: string): boolean {
    const path = currentPath.value;
    return path === prefix || path.startsWith(`${prefix}/`);
}

function isNavItemActive(item: MenuItem): boolean {
    if (item.matchPrefixes?.length) {
        return item.matchPrefixes.some(pathMatchesPrefix);
    }
    return isActivePath(item.href);
}

/** Team settings use `Settings/Team` for both `/settings/team` and `/teams/{id}`. */
const isTeamSettingsPath = computed(
    () => isActivePath(route('settings.team')) || /^\/teams\/\d+$/.test(currentPath.value),
);

const isSettingsSectionActive = computed(
    () => isActivePath(route('profile.show'))
        || isActivePath(route('settings.business'))
        || isActivePath(route('settings.features'))
        || pathMatchesPrefix('/settings/instance')
        || pathMatchesPrefix('/settings/note-templates')
        || isTeamSettingsPath.value,
);

const commandPaletteData = computed<PaletteData>(() => ({
    quickActions: (page.props.commandPalette?.quickActions ?? [
        canTeam('invoices.manage')
            ? { id: 'new-invoice', label: 'New Invoice', href: route('invoicing.invoices.create'), icon: 'invoice' as const }
            : null,
        canTeam('expenses.manage')
            ? { id: 'new-expense', label: 'New Expense', href: route('expenses.create'), icon: 'expense' as const }
            : null,
        canTeam('invoices.manage')
            ? { id: 'record-payment', label: 'Record Payment', href: `${route('dashboard')}#outstanding-invoices`, icon: 'payment' as const }
            : null,
        canTeam('clients.manage')
            ? { id: 'new-client', label: 'New Client', href: route('invoicing.clients.create'), icon: 'client' as const }
            : null,
    ].filter(Boolean)) as PaletteData['quickActions'],
    navigation: page.props.commandPalette?.navigation ?? [
        { id: 'dashboard', label: 'Dashboard', href: route('dashboard') },
        { id: 'invoices', label: 'Invoices', href: route('invoicing.invoices.index') },
        { id: 'estimates', label: 'Estimates', href: route('invoicing.estimates.index') },
        { id: 'clients', label: 'Clients', href: route('invoicing.clients.index') },
        { id: 'expenses', label: 'Expenses', href: route('expenses.index') },
        { id: 'suppliers', label: 'Suppliers', href: route('suppliers.index') },
        { id: 'banking-transactions', label: 'Banking Transactions', href: route('banking.transactions.index') },
        { id: 'vehicles-trips', label: 'Trip Log', href: route('vehicles.trips.index') },
        { id: 'vehicles', label: 'Vehicles', href: route('vehicles.index') },
        { id: 'accounting-transactions', label: 'Accounting Transactions', href: route('accounting.transactions.index') },
        { id: 'budgets', label: 'Budgets', href: route('budgeting.index') },
        { id: 'contracts', label: 'Contracts', href: route('contracting.contracts.index') },
        { id: 'profile', label: 'Settings', href: route('settings.index') },
    ],
    recent: page.props.commandPalette?.recent ?? {},
}));

const logout = () => router.post(route('logout'));
const switchTeam = (team: { id: number }) => router.put(route('current-team.update'), { team_id: team.id }, { preserveState: false });

const leaveTeamForm = useForm({});
const leaveTeamModalOpen = ref(false);
const authUserId = computed(() => page.props.auth?.user?.id as number | undefined);

const confirmLeaveCurrentTeam = () => {
    if (!currentTeam.value?.id || !authUserId.value) {
        return;
    }

    leaveTeamForm.delete(route('team-members.destroy', [currentTeam.value.id, authUserId.value]), {
        errorBag: 'removeTeamMember',
        onSuccess: () => {
            leaveTeamModalOpen.value = false;
        },
    });
};

const onGlobalKey = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        commandPaletteOpen.value = !commandPaletteOpen.value;
    }
};

onMounted(() => window.addEventListener('keydown', onGlobalKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKey));
</script>

<template>
    <div>
        <Head :title="title" />
        <ToastHost />

        <div class="min-h-screen bg-canvas-50 text-slate-900 lg:pl-0">
            <aside
                :class="[
                    'fixed inset-y-0 left-0 z-40 hidden border-r border-canvas-200 bg-canvas-100 text-slate-900 lg:flex lg:flex-col transition-all',
                    collapsed ? 'w-20' : 'w-[260px]',
                ]"
            >
                <div class="border-b border-canvas-200 px-4 py-4">
                    <Link
                        :href="route('dashboard')"
                        class="flex items-center gap-3"
                        :class="collapsed ? 'flex-col gap-2' : ''"
                    >
                        <ApplicationMark class="h-10 w-10 shrink-0 text-brand-700" />
                        <span v-if="!collapsed" class="font-semibold">{{ appDisplayName }}</span>
                    </Link>
                </div>

                <nav class="flex-1 overflow-y-auto px-2 py-3">
                    <template v-for="item in navItems" :key="item.label">
                        <div class="mb-1">
                            <Link
                                :href="item.href"
                                :class="[
                                    'flex w-full min-h-[2.5rem] items-center rounded-md border-l-2 px-3 py-2 text-left text-sm transition',
                                    collapsed ? 'justify-center' : '',
                                    isNavItemActive(item)
                                        ? 'border-l-brand-700 bg-brand-500/15 text-brand-800'
                                        : 'border-l-transparent text-slate-700 hover:bg-white/55 hover:text-slate-900',
                                ]"
                            >
                                <component :is="item.icon" class="h-4 w-4 shrink-0" />
                                <span v-if="!collapsed" class="ml-3">{{ item.label }}</span>
                            </Link>
                        </div>
                    </template>
                </nav>
            </aside>

            <div :class="[collapsed ? 'lg:pl-20' : 'lg:pl-[260px]']" class="transition-all">
                <header class="sticky top-0 z-30 border-b border-canvas-200 bg-canvas-50/95 backdrop-blur">
                    <div class="flex h-16 items-center justify-between px-4 sm:px-6 lg:px-8">
                        <div class="flex items-center gap-2">
                            <button class="rounded-md p-2 hover:bg-canvas-100 lg:hidden" @click="mobileOpen = true">
                                <Menu class="h-5 w-5" />
                            </button>
                            <button class="hidden rounded-md p-2 hover:bg-canvas-100 lg:inline-flex" @click="collapsed = !collapsed">
                                <PanelLeft class="h-5 w-5" />
                            </button>

                            <nav v-if="breadcrumbs?.length" class="hidden items-center gap-2 text-sm text-slate-500 md:flex">
                                <template v-for="(crumb, index) in breadcrumbs" :key="crumb.label">
                                    <Link v-if="crumb.href" :href="crumb.href" class="hover:text-slate-700">{{ crumb.label }}</Link>
                                    <span v-else class="text-slate-700">{{ crumb.label }}</span>
                                    <ChevronRight v-if="index < breadcrumbs.length - 1" class="h-3.5 w-3.5" />
                                </template>
                            </nav>
                        </div>

                        <div class="flex items-center gap-2 sm:gap-3">
                            <button
                                class="inline-flex h-9 items-center gap-2 rounded-md border border-canvas-200 bg-white/80 px-3 text-sm text-slate-600 hover:bg-white"
                                @click="commandPaletteOpen = true"
                            >
                                <Search class="h-4 w-4" />
                                <span class="hidden sm:inline">Search</span>
                                <kbd class="hidden rounded border border-slate-300 px-1 text-[11px] text-slate-500 sm:inline">⌘K</kbd>
                            </button>

                            <Dropdown v-if="hasTeamFeatures" align="right" width="60">
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="inline-flex h-9 max-w-[11rem] items-center gap-2 rounded-md border border-canvas-200 bg-white/90 px-2.5 text-sm text-slate-700 hover:bg-white sm:max-w-[14rem]"
                                        :aria-label="`Current business: ${currentTeam?.name ?? 'Business'}`"
                                    >
                                        <span
                                            class="flex h-5 w-5 shrink-0 items-center justify-center rounded text-slate-500"
                                            aria-hidden="true"
                                        >
                                            <Building2 class="h-3.5 w-3.5" />
                                        </span>
                                        <span class="min-w-0 truncate font-medium">{{ currentTeam?.name ?? 'Business' }}</span>
                                        <ChevronDown class="h-3.5 w-3.5 shrink-0 text-slate-400" />
                                    </button>
                                </template>
                                <template #content>
                                    <div class="w-60 py-1">
                                        <p class="px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                            Switch business
                                        </p>
                                        <template v-for="team in teams" :key="team.id">
                                            <form @submit.prevent="switchTeam(team)">
                                                <DropdownLink as="button">
                                                    <span class="flex min-w-0 items-center gap-2">
                                                        <span
                                                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-400"
                                                            aria-hidden="true"
                                                        >
                                                            <Building2 class="h-3.5 w-3.5" />
                                                        </span>
                                                        <span
                                                            class="min-w-0 truncate"
                                                            :class="team.id === currentTeam?.id ? 'font-semibold text-slate-900' : ''"
                                                        >
                                                            {{ team.name }}
                                                        </span>
                                                    </span>
                                                </DropdownLink>
                                            </form>
                                        </template>
                                        <template v-if="$page.props.jetstream.canCreateTeams">
                                            <div class="my-2 border-t border-slate-200" />
                                            <DropdownLink :href="route('teams.create')">
                                                <span class="flex min-w-0 items-center gap-2">
                                                    <span
                                                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-dashed border-slate-300 bg-white text-slate-500"
                                                        aria-hidden="true"
                                                    >
                                                        <Plus class="h-3.5 w-3.5" />
                                                    </span>
                                                    <span class="truncate">Create business</span>
                                                </span>
                                            </DropdownLink>
                                        </template>
                                        <template v-if="canLeaveCurrentTeam">
                                            <div class="my-2 border-t border-slate-200" />
                                            <button
                                                type="button"
                                                class="block w-full px-4 py-2 text-left text-sm text-rose-600 transition hover:bg-rose-50"
                                                @click="leaveTeamModalOpen = true"
                                            >
                                                <span class="flex min-w-0 items-center gap-2">
                                                    <span
                                                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md border border-rose-200 bg-white text-rose-600"
                                                        aria-hidden="true"
                                                    >
                                                        <LogOut class="h-3.5 w-3.5" />
                                                    </span>
                                                    <span class="truncate">Leave {{ currentTeam?.name ?? 'business' }}</span>
                                                </span>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                            </Dropdown>

                            <Dropdown align="right" width="60">
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="inline-flex h-9 items-center gap-2 rounded-md border border-canvas-200 bg-white/90 py-0 pl-1 pr-2 hover:bg-white sm:pr-2.5"
                                        :aria-label="`Account menu for ${authUser?.name ?? 'user'}`"
                                    >
                                        <img
                                            v-if="$page.props.jetstream.managesProfilePhotos"
                                            class="h-7 w-7 rounded-full object-cover"
                                            :src="authUser?.profile_photo_url"
                                            :alt="authUser?.name"
                                        >
                                        <span
                                            v-else
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-800"
                                        >
                                            {{ (authUser?.name || 'U').slice(0, 1).toUpperCase() }}
                                        </span>
                                        <span class="hidden max-w-[9rem] truncate text-sm font-medium text-slate-900 sm:inline">
                                            {{ authUser?.name }}
                                        </span>
                                        <ChevronDown class="hidden h-3.5 w-3.5 shrink-0 text-slate-400 sm:block" />
                                    </button>
                                </template>
                                <template #content>
                                    <div class="w-60">
                                        <div class="border-b border-slate-200 px-4 py-3">
                                            <p class="truncate text-sm font-semibold text-slate-900">{{ authUser?.name }}</p>
                                            <p class="truncate text-xs text-slate-500">{{ authUser?.email }}</p>
                                            <p
                                                v-if="currentTeamRoleLabel"
                                                class="mt-1.5 truncate text-xs font-medium text-slate-700"
                                            >
                                                {{ currentTeamRoleLabel }}
                                            </p>
                                        </div>
                                        <div class="py-1">
                                            <DropdownLink :href="route('profile.show')">Profile settings</DropdownLink>
                                            <DropdownLink v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')">API Tokens</DropdownLink>
                                            <div class="my-1 border-t border-slate-200" />
                                            <form @submit.prevent="logout">
                                                <DropdownLink as="button">Log Out</DropdownLink>
                                            </form>
                                        </div>
                                    </div>
                                </template>
                            </Dropdown>

                            <Link
                                :href="route('settings.index')"
                                :aria-label="SETTINGS_SECTION_LABEL"
                                :title="SETTINGS_SECTION_LABEL"
                                :class="[
                                    'inline-flex h-9 w-9 items-center justify-center rounded-md border transition',
                                    isSettingsSectionActive
                                        ? 'border-brand-200 bg-brand-50 text-brand-700'
                                        : 'border-canvas-200 bg-white/80 text-slate-600 hover:bg-white',
                                ]"
                            >
                                <Settings class="h-4 w-4" />
                            </Link>

                            <button class="inline-flex h-9 w-9 items-center justify-center rounded-md text-slate-600 hover:bg-canvas-100">
                                <Bell class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                </header>

                <main class="pb-28 lg:pb-8">
                    <div class="px-4 py-6 sm:px-6 lg:px-8">
                        <header v-if="$slots.header" class="mb-6">
                            <slot name="header" />
                        </header>
                        <slot />
                    </div>
                </main>
            </div>

            <div v-if="mobileOpen" class="fixed inset-0 z-50 bg-black/50 lg:hidden" @click="mobileOpen = false" />
            <aside
                :class="[
                    'fixed inset-y-0 left-0 z-[60] w-[260px] bg-canvas-100 p-4 text-slate-900 shadow-xl transition-transform lg:hidden',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                ]"
            >
                <div class="mb-4 flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <ApplicationMark class="h-8 w-8 shrink-0 text-brand-700" />
                            <span class="font-semibold">{{ appDisplayName }}</span>
                        </div>
                    </div>
                    <button class="rounded-md p-2 hover:bg-white/55" @click="mobileOpen = false">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <div class="space-y-1">
                    <template v-for="item in navItems" :key="`m-${item.label}`">
                        <Link
                            :href="item.href"
                            :class="[
                                'block rounded-md px-3 py-2 text-sm',
                                isNavItemActive(item) ? 'bg-brand-500/15 font-medium text-brand-800' : 'hover:bg-white/55',
                            ]"
                            @click="mobileOpen = false"
                        >
                            {{ item.label }}
                        </Link>
                    </template>
                </div>
            </aside>

            <nav
                class="fixed inset-x-0 bottom-0 z-40 border-t border-canvas-200 bg-canvas-50 pb-[env(safe-area-inset-bottom)] lg:hidden"
            >
                <div class="relative grid min-h-[3.5rem] grid-cols-5 items-end">
                    <Link
                        :href="route('dashboard')"
                        :class="[
                            'flex min-h-12 flex-col items-center justify-center gap-0.5 pb-2 text-[10px] font-medium',
                            isActivePath(route('dashboard')) ? 'text-brand-700' : 'text-slate-600',
                        ]"
                    >
                        <Home class="h-5 w-5 shrink-0" />
                        <span>Home</span>
                    </Link>
                    <Link
                        v-if="canTeam('invoices.view')"
                        :href="route('invoicing.invoices.index')"
                        :class="[
                            'flex min-h-12 flex-col items-center justify-center gap-0.5 pb-2 text-[10px] font-medium',
                            isActivePath(route('invoicing.invoices.index')) ? 'text-brand-700' : 'text-slate-600',
                        ]"
                    >
                        <FileText class="h-5 w-5 shrink-0" />
                        <span>Invoices</span>
                    </Link>
                    <div v-if="canTeam('invoices.manage') || canTeam('expenses.manage') || canTeam('clients.manage')" class="flex justify-center">
                        <button
                            type="button"
                            class="relative -top-5 flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-brand-500 text-white shadow-lg ring-4 ring-white"
                            aria-label="Quick add"
                            @click="quickAddOpen = true"
                        >
                            <Plus class="h-7 w-7" />
                        </button>
                    </div>
                    <template v-if="vatEnabled && canTeam('reports.view')">
                        <Link
                            :href="route('reports.profit-loss')"
                            :class="[
                                'flex min-h-12 flex-col items-center justify-center gap-0.5 pb-2 text-[10px] font-medium',
                                isActivePath(route('reports.profit-loss')) ? 'text-brand-700' : 'text-slate-600',
                            ]"
                        >
                            <ChartColumnBig class="h-5 w-5 shrink-0" />
                            <span>Reports</span>
                        </Link>
                    </template>
                    <template v-else-if="!vatEnabled && canTeam('settings.business')">
                        <a
                            :href="route('settings.business', { tab: 'tax' })"
                            class="flex min-h-12 flex-col items-center justify-center gap-0.5 pb-2 text-[10px] font-medium text-slate-400"
                        >
                            <Calculator class="h-5 w-5 shrink-0" />
                            <span>VAT Off</span>
                        </a>
                    </template>
                    <button
                        type="button"
                        class="flex min-h-12 flex-col items-center justify-center gap-0.5 pb-2 text-[10px] font-medium text-slate-600"
                        @click="mobileOpen = true"
                    >
                        <MoreHorizontal class="h-5 w-5 shrink-0" />
                        <span>More</span>
                    </button>
                </div>
            </nav>
        </div>

        <Teleport to="body">
            <div
                v-if="quickAddOpen"
                class="fixed inset-0 z-[70] bg-black/40 lg:hidden"
                @click.self="quickAddOpen = false"
            >
                <div
                    class="absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-2xl bg-white px-4 pt-3 shadow-xl pb-[calc(1rem+env(safe-area-inset-bottom))]"
                    role="dialog"
                    aria-label="Quick add"
                >
                    <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-slate-200" />
                    <p class="mb-3 text-center text-sm font-semibold text-slate-900">
                        Quick add
                    </p>
                    <div class="grid gap-2">
                        <Link
                            v-if="canTeam('expenses.manage')"
                            :href="route('expenses.create')"
                            class="flex min-h-12 items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-left text-sm font-medium text-slate-900 active:bg-slate-50"
                            @click="quickAddOpen = false"
                        >
                            <Receipt class="h-5 w-5 shrink-0 text-brand-700" />
                            New expense
                        </Link>
                        <Link
                            v-if="canTeam('invoices.manage')"
                            :href="route('invoicing.invoices.create')"
                            class="flex min-h-12 items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-left text-sm font-medium text-slate-900 active:bg-slate-50"
                            @click="quickAddOpen = false"
                        >
                            <FileText class="h-5 w-5 shrink-0 text-brand-700" />
                            New invoice
                        </Link>
                        <Link
                            v-if="canTeam('invoices.manage')"
                            :href="`${route('dashboard')}#outstanding-invoices`"
                            class="flex min-h-12 items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-left text-sm font-medium text-slate-900 active:bg-slate-50"
                            @click="quickAddOpen = false"
                        >
                            <CreditCard class="h-5 w-5 shrink-0 text-brand-700" />
                            Record payment
                        </Link>
                    </div>
                    <button
                        type="button"
                        class="mt-3 w-full min-h-12 rounded-xl py-3 text-sm font-medium text-slate-600 hover:bg-slate-50"
                        @click="quickAddOpen = false"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </Teleport>

        <CommandPalette v-model:open="commandPaletteOpen" :data="commandPaletteData" />
        <SessionIdleWatcher />

        <div
            v-if="leaveTeamModalOpen && currentTeam"
            class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-4"
            @click.self="leaveTeamModalOpen = false"
        >
            <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-lg">
                <h3 class="text-base font-semibold text-slate-900">Leave {{ currentTeam.name }}?</h3>
                <p class="mt-2 text-sm text-slate-600">
                    You will lose access to this business immediately. If you belong to another business, we will switch you there.
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <AppButton variant="ghost" @click="leaveTeamModalOpen = false">Cancel</AppButton>
                    <AppButton
                        variant="primary"
                        class="!bg-rose-600"
                        :disabled="leaveTeamForm.processing"
                        @click="confirmLeaveCurrentTeam"
                    >
                        Leave business
                    </AppButton>
                </div>
            </div>
        </div>
    </div>
</template>
