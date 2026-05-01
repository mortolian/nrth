<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    BookOpen,
    Briefcase,
    Building2,
    Calculator,
    ChartColumnBig,
    ChevronDown,
    ChevronRight,
    CreditCard,
    FileText,
    FolderKanban,
    Home,
    Landmark,
    Menu,
    MoreHorizontal,
    Plus,
    Receipt,
    Search,
    Settings,
    Wallet,
    X,
} from 'lucide-vue-next';
import ApplicationMark from '@/Components/ApplicationMark.vue';
import Banner from '@/Components/Banner.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import CommandPalette from '@/Components/layout/CommandPalette.vue';
import { useAppDisplayName } from '@/lib/appName';

const NAV_SECTIONS_EXPANDED_KEY = 'nrth:nav-sections-expanded:v1';
const SETTINGS_SECTION_LABEL = 'Settings';

function loadNavSectionsExpanded(): Record<string, boolean> {
    if (typeof window === 'undefined') return {};
    try {
        const raw = localStorage.getItem(NAV_SECTIONS_EXPANDED_KEY);
        if (!raw) return {};
        const parsed = JSON.parse(raw) as unknown;
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return {};
        return parsed as Record<string, boolean>;
    } catch {
        return {};
    }
}

function persistNavSectionsExpanded(state: Record<string, boolean>): void {
    if (typeof window === 'undefined') return;
    try {
        localStorage.setItem(NAV_SECTIONS_EXPANDED_KEY, JSON.stringify(state));
    } catch {
        /* quota / private mode */
    }
}

type Breadcrumb = { label: string; href?: string };
type NavChild = { label: string; href: string };
type NavGroup = { title: string; items: NavChild[] };
type MenuItem = { label: string; href: string; icon: unknown; group?: NavGroup[] };
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
const hasTeamFeatures = computed(() => Boolean(page.props.jetstream?.hasTeamFeatures));
const currentPath = computed(() => page.url.split('?')[0]);
const vatEnabled = computed(() => Boolean(page.props.vat_enabled));

const navItems = computed<MenuItem[]>(() => {
    const items: MenuItem[] = [
        { label: 'Dashboard', href: route('dashboard'), icon: Home },
        {
            label: 'Money In',
            href: route('invoicing.invoices.index'),
            icon: Wallet,
            group: [{ title: 'Money In', items: [{ label: 'Invoices', href: route('invoicing.invoices.index') }, { label: 'Estimates', href: route('invoicing.estimates.index') }, { label: 'Clients', href: route('invoicing.clients.index') }] }],
        },
        {
            label: 'Money Out',
            href: route('expenses.index'),
            icon: Landmark,
            group: [{ title: 'Money Out', items: [{ label: 'Expenses', href: route('expenses.index') }, { label: 'Suppliers', href: route('suppliers.index') }] }],
        },
        {
            label: 'Accounting',
            href: route('accounting.transactions.index'),
            icon: BookOpen,
            group: [{ title: 'Accounting', items: [{ label: 'Transactions', href: route('accounting.transactions.index') }, { label: 'General Ledger', href: route('accounting.journal.index') }, { label: 'Chart of Accounts', href: route('accounting.accounts.index') }] }],
        },
        { label: 'Planning', href: route('budgeting.index'), icon: FolderKanban, group: [{ title: 'Planning', items: [{ label: 'Budgets', href: route('budgeting.index') }] }] },
        {
            label: 'Contracting',
            href: route('contracting.contracts.index'),
            icon: Briefcase,
            group: [{ title: 'Contracting', items: [{ label: 'Contracts', href: route('contracting.contracts.index') }] }],
        },
    ];

    if (vatEnabled.value) {
        items.push({
            label: 'Tax',
            href: route('tax.vat.index'),
            icon: Calculator,
            group: [{
                title: 'Tax',
                items: [
                    { label: 'VAT Returns', href: route('tax.vat.index') },
                    { label: 'VAT rates', href: route('tax.vat-rates.index') },
                    { label: 'Tax Periods', href: route('tax.provisional.index') },
                    { label: 'Documents', href: route('tax.documents.index') },
                ],
            }],
        });
        items.push({
            label: 'Reports',
            href: route('reports.profit-loss'),
            icon: ChartColumnBig,
            group: [{ title: 'Reports', items: [{ label: 'Profit And Loss', href: route('reports.profit-loss') }, { label: 'Balance Sheet', href: route('reports.balance-sheet') }, { label: 'Cash Flow', href: route('reports.cash-flow') }, { label: 'Trial Balance', href: route('reports.trial-balance') }] }],
        });
    }

    return items;
});

const isActivePath = (href: string) => href !== '#' && currentPath.value === href.split('?')[0];

/** User expand/collapse for nav groups; persisted in localStorage. */
const navManualOverride = ref<Record<string, boolean>>(loadNavSectionsExpanded());

watch(
    navManualOverride,
    (val) => persistNavSectionsExpanded(val),
    { deep: true },
);

function sectionHasActiveChild(item: MenuItem): boolean {
    const items = item.group?.[0]?.items;
    if (!items) return false;
    return items.some((sub) => sub.href !== '#' && isActivePath(sub.href));
}

function sectionDefaultExpanded(item: MenuItem): boolean {
    return isActive(item.href) || sectionHasActiveChild(item);
}

function isNavItemOrChildActive(item: MenuItem): boolean {
    return isActive(item.href) || sectionHasActiveChild(item);
}

function isNavSectionExpanded(item: MenuItem): boolean {
    if (!item.group?.length) return false;
    const o = navManualOverride.value[item.label];
    const active = sectionDefaultExpanded(item);
    if (active) {
        return o !== false;
    }
    return o === true;
}

function toggleNavSection(label: string): void {
    const item = navItems.value.find((i) => i.label === label);
    if (!item?.group) return;
    const next = !isNavSectionExpanded(item);
    navManualOverride.value = { ...navManualOverride.value, [label]: next };
}

/** Group parents navigate only via children; the row expands/collapses (or expands the sidebar when icon-only). */
function onNavGroupRowClick(item: MenuItem): void {
    if (!item.group) return;
    if (collapsed.value) {
        collapsed.value = false;
        navManualOverride.value = { ...navManualOverride.value, [item.label]: true };
        return;
    }
    toggleNavSection(item.label);
}

function navSectionDomId(label: string): string {
    return 'nav-section-' + label.toLowerCase().replace(/\s+/g, '-');
}

/** Team settings use `Settings/Team` for both `/settings/team` and `/teams/{id}`. */
const isTeamSettingsPath = computed(
    () => isActivePath(route('settings.team')) || /^\/teams\/\d+$/.test(currentPath.value),
);

const isSettingsSectionActive = computed(
    () => isActivePath(route('profile.show')) || isActivePath(route('settings.company')) || isTeamSettingsPath.value,
);

/** Collapsed unless the user explicitly opens the section (not auto-expanded on settings routes). */
const isSettingsSectionExpanded = computed(
    () => navManualOverride.value[SETTINGS_SECTION_LABEL] === true,
);

function toggleSettingsSection(): void {
    const next = !isSettingsSectionExpanded.value;
    navManualOverride.value = { ...navManualOverride.value, [SETTINGS_SECTION_LABEL]: next };
}

function onSettingsRowClick(): void {
    if (collapsed.value) {
        collapsed.value = false;
        navManualOverride.value = { ...navManualOverride.value, [SETTINGS_SECTION_LABEL]: true };
        return;
    }
    toggleSettingsSection();
}

const commandPaletteData = computed<PaletteData>(() => ({
    quickActions: page.props.commandPalette?.quickActions ?? [
        { id: 'new-invoice', label: 'New Invoice', href: '#', icon: 'invoice' },
        { id: 'new-expense', label: 'New Expense', href: '#', icon: 'expense' },
        { id: 'record-payment', label: 'Record Payment', href: '#', icon: 'payment' },
        { id: 'new-client', label: 'New Client', href: '#', icon: 'client' },
    ],
    navigation: page.props.commandPalette?.navigation ?? [
        { id: 'dashboard', label: 'Dashboard', href: route('dashboard') },
        { id: 'profile', label: 'Profile Settings', href: route('profile.show') },
    ],
    recent: page.props.commandPalette?.recent ?? {},
}));

const logout = () => router.post(route('logout'));
const switchTeam = (team: { id: number }) => router.put(route('current-team.update'), { team_id: team.id }, { preserveState: false });
const isActive = (href: string) => isActivePath(href);

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
        <Banner />

        <div class="min-h-screen bg-white text-slate-900 lg:pl-0">
            <aside
                :class="[
                    'fixed inset-y-0 left-0 z-40 hidden border-r border-slate-900/10 bg-[#F8FAFD] text-slate-900 lg:flex lg:flex-col transition-all',
                    collapsed ? 'w-20' : 'w-[260px]',
                ]"
            >
                <div class="border-b border-slate-900/10 px-4 py-4">
                    <Link :href="route('dashboard')" class="flex items-center gap-3">
                        <ApplicationMark class="h-10 w-10 shrink-0 text-brand-700" />
                        <span v-if="!collapsed" class="font-semibold">{{ appDisplayName }}</span>
                    </Link>

                    <div v-if="hasTeamFeatures && !collapsed" class="mt-4">
                        <Dropdown align="left" width="60">
                            <template #trigger>
                                <button class="flex w-full items-center justify-between rounded-md bg-white/50 px-3 py-2 text-sm hover:bg-white/70">
                                    <span class="truncate">{{ currentTeam?.name ?? 'Team' }}</span>
                                    <ChevronRight class="h-4 w-4" />
                                </button>
                            </template>
                            <template #content>
                                <div class="w-60">
                                    <a
                                        :href="route('settings.team')"
                                        class="block px-4 py-2 text-sm leading-5 text-slate-700 hover:bg-slate-100 focus:bg-slate-100 focus:outline-none"
                                    >
                                        Team and Member Settings
                                    </a>
                                    <DropdownLink v-if="$page.props.jetstream.canCreateTeams" :href="route('teams.create')">Create Team</DropdownLink>
                                    <div class="my-2 border-t border-slate-200" />
                                    <template v-for="team in teams" :key="team.id">
                                        <form @submit.prevent="switchTeam(team)">
                                            <DropdownLink as="button">{{ team.name }}</DropdownLink>
                                        </form>
                                    </template>
                                </div>
                            </template>
                        </Dropdown>
                    </div>
                </div>

                <nav class="flex-1 overflow-y-auto px-2 py-3">
                    <template v-for="item in navItems" :key="item.label">
                        <div v-if="!item.group" class="mb-1">
                            <Link
                                :href="item.href"
                                :class="[
                                    'flex w-full min-h-[2.5rem] items-center rounded-md border-l-2 px-3 py-2 text-left text-sm transition',
                                    collapsed ? 'justify-center' : '',
                                    isActive(item.href)
                                        ? 'border-l-brand-700 bg-brand-500/25 text-brand-800'
                                        : 'border-l-transparent text-slate-700 hover:bg-white/40 hover:text-slate-900',
                                ]"
                            >
                                <component :is="item.icon" class="h-4 w-4 shrink-0" />
                                <span v-if="!collapsed" class="ml-3">{{ item.label }}</span>
                            </Link>
                        </div>

                        <div v-else class="mb-1">
                            <button
                                type="button"
                                :class="[
                                    'flex w-full min-h-[2.5rem] items-center rounded-md border-l-2 px-3 py-2 text-left text-sm transition',
                                    collapsed ? 'justify-center' : '',
                                    isNavItemOrChildActive(item)
                                        ? 'border-l-brand-700 bg-brand-500/25 text-brand-800'
                                        : 'border-l-transparent text-slate-700 hover:bg-white/30 hover:text-slate-900',
                                ]"
                                :aria-expanded="!collapsed && isNavSectionExpanded(item)"
                                :aria-controls="navSectionDomId(item.label)"
                                @click="onNavGroupRowClick(item)"
                            >
                                <component :is="item.icon" class="h-4 w-4 shrink-0" />
                                <span v-if="!collapsed" class="ml-3 min-w-0 flex-1 truncate">{{ item.label }}</span>
                                <ChevronDown
                                    v-if="!collapsed"
                                    class="h-4 w-4 shrink-0 text-slate-700 transition-transform duration-200"
                                    :class="isNavSectionExpanded(item) ? 'rotate-180' : ''"
                                />
                            </button>

                            <div
                                :id="navSectionDomId(item.label)"
                                v-show="isNavSectionExpanded(item) && !collapsed"
                                class="ml-9 mt-1 space-y-1 border-l border-slate-900/20 pl-2"
                            >
                                <Link
                                    v-for="sub in item.group[0].items"
                                    :key="`${item.label}-${sub.label}`"
                                    :href="sub.href"
                                    :class="[
                                        'block rounded px-2 py-1 text-xs transition',
                                        isActivePath(sub.href)
                                            ? 'bg-brand-500/30 font-medium text-brand-800'
                                            : 'text-slate-700 hover:bg-white/40 hover:text-slate-900',
                                    ]"
                                >
                                    {{ sub.label }}
                                </Link>
                            </div>
                        </div>
                    </template>
                    <div
                        v-if="!vatEnabled && !collapsed"
                        class="mx-2 mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600"
                    >
                        VAT is disabled. VAT and report menus are hidden.
                        <a :href="route('settings.company', { tab: 'tax' })" class="ml-1 font-medium text-brand-700 hover:underline">
                            Enable in Company settings
                        </a>
                    </div>
                </nav>

                <div class="border-t border-slate-900/10 p-2">
                    <button
                        type="button"
                        :class="[
                            'flex w-full min-h-[2.5rem] items-center rounded-md border-l-2 px-3 py-2 text-left text-sm transition',
                            collapsed ? 'justify-center' : '',
                            isSettingsSectionActive
                                ? 'border-l-brand-700 bg-brand-500/25 text-brand-800'
                                : 'border-l-transparent text-slate-700 hover:bg-white/40 hover:text-slate-900',
                        ]"
                        :aria-expanded="!collapsed && isSettingsSectionExpanded"
                        :aria-controls="navSectionDomId(SETTINGS_SECTION_LABEL)"
                        @click="onSettingsRowClick"
                    >
                        <Settings class="h-4 w-4 shrink-0" />
                        <span v-if="!collapsed" class="ml-3 min-w-0 flex-1 truncate">{{ SETTINGS_SECTION_LABEL }}</span>
                        <ChevronDown
                            v-if="!collapsed"
                            class="h-4 w-4 shrink-0 text-slate-700 transition-transform duration-200"
                            :class="isSettingsSectionExpanded ? 'rotate-180' : ''"
                        />
                    </button>
                    <div
                        :id="navSectionDomId(SETTINGS_SECTION_LABEL)"
                        v-show="isSettingsSectionExpanded && !collapsed"
                        class="ml-9 mt-1 space-y-1 border-l border-slate-900/20 pl-2"
                    >
                        <Link
                            :href="route('profile.show')"
                            :class="[
                                'block rounded px-2 py-1 text-xs transition',
                                isActivePath(route('profile.show'))
                                    ? 'bg-brand-500/30 font-medium text-brand-800'
                                    : 'text-slate-700 hover:bg-white/40 hover:text-slate-900',
                            ]"
                        >
                            Profile
                        </Link>
                        <Link
                            :href="route('settings.company')"
                            :class="[
                                'block rounded px-2 py-1 text-xs transition',
                                isActivePath(route('settings.company'))
                                    ? 'bg-brand-500/30 font-medium text-brand-800'
                                    : 'text-slate-700 hover:bg-white/40 hover:text-slate-900',
                            ]"
                        >
                            Company
                        </Link>
                        <a
                            :href="route('settings.team')"
                            :class="[
                                'block rounded px-2 py-1 text-xs transition',
                                isTeamSettingsPath
                                    ? 'bg-brand-500/30 font-medium text-brand-800'
                                    : 'text-slate-700 hover:bg-white/40 hover:text-slate-900',
                            ]"
                        >
                            Teams and Members
                        </a>
                    </div>
                </div>
            </aside>

            <div :class="[collapsed ? 'lg:pl-20' : 'lg:pl-[260px]']" class="transition-all">
                <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                    <div class="flex h-16 items-center justify-between px-4 sm:px-6 lg:px-8">
                        <div class="flex items-center gap-2">
                            <button class="rounded-md p-2 hover:bg-slate-100 lg:hidden" @click="mobileOpen = true">
                                <Menu class="h-5 w-5" />
                            </button>
                            <button class="hidden rounded-md p-2 hover:bg-slate-100 lg:inline-flex" @click="collapsed = !collapsed">
                                <Building2 class="h-5 w-5" />
                            </button>

                            <nav v-if="breadcrumbs?.length" class="hidden items-center gap-2 text-sm text-slate-500 md:flex">
                                <template v-for="(crumb, index) in breadcrumbs" :key="crumb.label">
                                    <Link v-if="crumb.href" :href="crumb.href" class="hover:text-slate-700">{{ crumb.label }}</Link>
                                    <span v-else class="text-slate-700">{{ crumb.label }}</span>
                                    <ChevronRight v-if="index < breadcrumbs.length - 1" class="h-3.5 w-3.5" />
                                </template>
                            </nav>
                        </div>

                        <div class="flex items-center gap-2">
                            <button
                                class="inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                                @click="commandPaletteOpen = true"
                            >
                                <Search class="h-4 w-4" />
                                <span class="hidden sm:inline">Search</span>
                                <kbd class="hidden rounded border border-slate-300 px-1 text-[11px] text-slate-500 sm:inline">⌘K</kbd>
                            </button>
                            <button class="rounded-md p-2 text-slate-600 hover:bg-slate-100">
                                <Bell class="h-5 w-5" />
                            </button>
                            <Dropdown align="right" width="48">
                                <template #trigger>
                                    <button class="rounded-full border border-slate-200 p-1.5 hover:bg-slate-50">
                                        <img
                                            v-if="$page.props.jetstream.managesProfilePhotos"
                                            class="h-7 w-7 rounded-full object-cover"
                                            :src="$page.props.auth.user.profile_photo_url"
                                            :alt="$page.props.auth.user.name"
                                        >
                                        <span v-else class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700">
                                            {{ ($page.props.auth.user.name || 'U').slice(0, 1).toUpperCase() }}
                                        </span>
                                    </button>
                                </template>
                                <template #content>
                                    <DropdownLink :href="route('profile.show')">Profile</DropdownLink>
                                    <DropdownLink v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')">API Tokens</DropdownLink>
                                    <div class="my-2 border-t border-slate-200" />
                                    <form @submit.prevent="logout">
                                        <DropdownLink as="button">Log Out</DropdownLink>
                                    </form>
                                </template>
                            </Dropdown>
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
                    'fixed inset-y-0 left-0 z-[60] w-[260px] bg-[#F8FAFD] p-4 text-slate-900 shadow-xl transition-transform lg:hidden',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                ]"
            >
                <div class="mb-4 flex items-center justify-between">
                    <span class="font-semibold">Menu</span>
                    <button class="rounded-md p-2 hover:bg-white/40" @click="mobileOpen = false">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <div class="space-y-1">
                    <template v-for="item in navItems" :key="`m-${item.label}`">
                        <div v-if="!item.group">
                            <Link
                                :href="item.href"
                                class="block rounded-md px-3 py-2 text-sm hover:bg-white/40"
                                @click="mobileOpen = false"
                            >
                                {{ item.label }}
                            </Link>
                        </div>
                        <div v-else>
                            <button
                                type="button"
                                class="flex w-full items-center rounded-md px-3 py-2 text-left text-sm hover:bg-white/30"
                                :aria-expanded="isNavSectionExpanded(item)"
                                :aria-controls="'m-' + navSectionDomId(item.label)"
                                @click="toggleNavSection(item.label)"
                            >
                                <span class="min-w-0 flex-1">{{ item.label }}</span>
                                <ChevronDown
                                    class="h-4 w-4 shrink-0 text-slate-700 transition-transform duration-200"
                                    :class="isNavSectionExpanded(item) ? 'rotate-180' : ''"
                                />
                            </button>
                            <div
                                :id="'m-' + navSectionDomId(item.label)"
                                v-show="isNavSectionExpanded(item)"
                                class="ml-3 mt-1 space-y-0.5 border-l border-slate-900/20 pl-2"
                            >
                                <Link
                                    v-for="sub in item.group[0].items"
                                    :key="`m-${item.label}-${sub.label}`"
                                    :href="sub.href"
                                    class="block rounded px-2 py-1.5 text-xs text-slate-700 hover:bg-white/40 hover:text-slate-900"
                                    @click="mobileOpen = false"
                                >
                                    {{ sub.label }}
                                </Link>
                            </div>
                        </div>
                    </template>
                    <div v-if="!vatEnabled" class="mx-1 mt-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        VAT is disabled. VAT and report menus are hidden.
                        <a :href="route('settings.company', { tab: 'tax' })" class="ml-1 font-medium text-brand-700 hover:underline">
                            Enable
                        </a>
                    </div>
                    <button
                        type="button"
                        class="flex w-full items-center rounded-md px-3 py-2 text-left text-sm hover:bg-white/30"
                        :aria-expanded="isSettingsSectionExpanded"
                        :aria-controls="'m-' + navSectionDomId(SETTINGS_SECTION_LABEL)"
                        @click="toggleSettingsSection"
                    >
                        <span class="min-w-0 flex-1">{{ SETTINGS_SECTION_LABEL }}</span>
                        <ChevronDown
                            class="h-4 w-4 shrink-0 text-slate-700 transition-transform duration-200"
                            :class="isSettingsSectionExpanded ? 'rotate-180' : ''"
                        />
                    </button>
                    <div
                        :id="'m-' + navSectionDomId(SETTINGS_SECTION_LABEL)"
                        v-show="isSettingsSectionExpanded"
                        class="ml-3 mt-1 space-y-0.5 border-l border-slate-900/20 pl-2"
                    >
                        <Link
                            :href="route('profile.show')"
                            class="block rounded px-2 py-1.5 text-xs text-slate-700 hover:bg-white/40 hover:text-slate-900"
                            @click="mobileOpen = false"
                        >
                            Profile
                        </Link>
                        <Link
                            :href="route('settings.company')"
                            class="block rounded px-2 py-1.5 text-xs text-slate-700 hover:bg-white/40 hover:text-slate-900"
                            @click="mobileOpen = false"
                        >
                            Company
                        </Link>
                        <a
                            :href="route('settings.team')"
                            class="block rounded px-2 py-1.5 text-xs text-slate-700 hover:bg-white/40 hover:text-slate-900"
                            @click="mobileOpen = false"
                        >
                            Teams and Members
                        </a>
                    </div>
                </div>
            </aside>

            <nav
                class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] lg:hidden"
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
                        :href="route('invoicing.invoices.index')"
                        :class="[
                            'flex min-h-12 flex-col items-center justify-center gap-0.5 pb-2 text-[10px] font-medium',
                            isActivePath(route('invoicing.invoices.index')) ? 'text-brand-700' : 'text-slate-600',
                        ]"
                    >
                        <FileText class="h-5 w-5 shrink-0" />
                        <span>Invoices</span>
                    </Link>
                    <div class="flex justify-center">
                        <button
                            type="button"
                            class="relative -top-5 flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-brand-500 text-white shadow-lg ring-4 ring-white"
                            aria-label="Quick add"
                            @click="quickAddOpen = true"
                        >
                            <Plus class="h-7 w-7" />
                        </button>
                    </div>
                    <template v-if="vatEnabled">
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
                    <template v-else>
                        <a
                            :href="route('settings.company', { tab: 'tax' })"
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
                            :href="route('expenses.create')"
                            class="flex min-h-12 items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-left text-sm font-medium text-slate-900 active:bg-slate-50"
                            @click="quickAddOpen = false"
                        >
                            <Receipt class="h-5 w-5 shrink-0 text-brand-700" />
                            New expense
                        </Link>
                        <Link
                            :href="route('invoicing.invoices.create')"
                            class="flex min-h-12 items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-left text-sm font-medium text-slate-900 active:bg-slate-50"
                            @click="quickAddOpen = false"
                        >
                            <FileText class="h-5 w-5 shrink-0 text-brand-700" />
                            New invoice
                        </Link>
                        <Link
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
    </div>
</template>
