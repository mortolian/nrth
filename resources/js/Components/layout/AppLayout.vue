<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    BookOpen,
    Briefcase,
    Building2,
    Calculator,
    ChartColumnBig,
    ChevronRight,
    Clock,
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

const currentTeam = computed(() => page.props.auth?.user?.current_team);
const teams = computed(() => page.props.auth?.user?.all_teams ?? []);
const hasTeamFeatures = computed(() => Boolean(page.props.jetstream?.hasTeamFeatures));
const currentPath = computed(() => page.url.split('?')[0]);

const navItems: MenuItem[] = [
    { label: 'Dashboard', href: route('dashboard'), icon: Home },
    {
        label: 'Money In',
        href: route('invoicing.invoices.index'),
        icon: Wallet,
        group: [{ title: 'Money In', items: [{ label: 'Invoices', href: route('invoicing.invoices.index') }, { label: 'Quotes', href: '#' }, { label: 'Clients', href: route('invoicing.clients.index') }] }],
    },
    {
        label: 'Money Out',
        href: route('expenses.index'),
        icon: Landmark,
        group: [{ title: 'Money Out', items: [{ label: 'Expenses', href: route('expenses.index') }, { label: 'Suppliers', href: '#' }] }],
    },
    {
        label: 'Accounting',
        href: route('accounting.transactions.index'),
        icon: BookOpen,
        group: [{ title: 'Accounting', items: [{ label: 'Transactions', href: route('accounting.transactions.index') }, { label: 'Journal', href: '#' }, { label: 'Chart of Accounts', href: '#' }] }],
    },
    { label: 'Planning', href: route('budgeting.index'), icon: FolderKanban, group: [{ title: 'Planning', items: [{ label: 'Budgets', href: route('budgeting.index') }] }] },
    {
        label: 'Tax',
        href: route('tax.vat.index'),
        icon: Calculator,
        group: [{ title: 'Tax', items: [{ label: 'VAT Returns', href: route('tax.vat.index') }, { label: 'Tax Periods', href: route('tax.provisional.index') }, { label: 'Documents', href: route('tax.documents.index') }] }],
    },
    {
        label: 'Contracting',
        href: route('contracting.contracts.index'),
        icon: Briefcase,
        group: [{ title: 'Contracting', items: [{ label: 'Contracts', href: route('contracting.contracts.index') }, { label: 'Time tracking', href: route('time.index') }] }],
    },
    {
        label: 'Reports',
        href: route('reports.profit-loss'),
        icon: ChartColumnBig,
        group: [{ title: 'Reports', items: [{ label: 'P&L', href: route('reports.profit-loss') }, { label: 'Balance Sheet', href: route('reports.balance-sheet') }, { label: 'Cash Flow', href: route('reports.cash-flow') }, { label: 'Trial Balance', href: route('reports.trial-balance') }] }],
    },
];

const isActivePath = (href: string) => href !== '#' && currentPath.value === href.split('?')[0];

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
                    'fixed inset-y-0 left-0 z-40 hidden border-r border-slate-800/80 bg-[#0f1117] text-slate-100 lg:flex lg:flex-col transition-all',
                    collapsed ? 'w-20' : 'w-[260px]',
                ]"
            >
                <div class="border-b border-slate-800 px-4 py-4">
                    <Link :href="route('dashboard')" class="flex items-center gap-3">
                        <ApplicationMark class="h-8 w-8 shrink-0" />
                        <span v-if="!collapsed" class="font-semibold">Spennies</span>
                    </Link>

                    <div v-if="hasTeamFeatures && !collapsed" class="mt-4">
                        <Dropdown align="left" width="60">
                            <template #trigger>
                                <button class="flex w-full items-center justify-between rounded-md bg-slate-800 px-3 py-2 text-sm hover:bg-slate-700">
                                    <span class="truncate">{{ currentTeam?.name ?? 'Team' }}</span>
                                    <ChevronRight class="h-4 w-4" />
                                </button>
                            </template>
                            <template #content>
                                <div class="w-60">
                                    <DropdownLink :href="route('teams.show', currentTeam)">Team Settings</DropdownLink>
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
                    <div v-for="item in navItems" :key="item.label" class="mb-1">
                        <Link
                            :href="item.href"
                            :class="[
                                'group flex items-center rounded-md border-l-2 px-3 py-2 text-sm transition',
                                isActive(item.href)
                                    ? 'border-l-[#00a86b] bg-emerald-500/15 text-emerald-300'
                                    : 'border-l-transparent text-slate-300 hover:bg-slate-800 hover:text-white',
                            ]"
                        >
                            <component :is="item.icon" class="h-4 w-4 shrink-0" />
                            <span v-if="!collapsed" class="ml-3">{{ item.label }}</span>
                        </Link>

                        <div v-if="item.group && !collapsed" class="ml-9 mt-1 space-y-1">
                            <Link
                                v-for="sub in item.group[0].items"
                                :key="`${item.label}-${sub.label}`"
                                :href="sub.href"
                                class="block rounded px-2 py-1 text-xs text-slate-400 hover:bg-slate-800 hover:text-slate-200"
                            >
                                {{ sub.label }}
                            </Link>
                        </div>
                    </div>
                </nav>

                <div class="border-t border-slate-800 p-2">
                    <div>
                        <Link
                            :href="route('profile.show')"
                            :class="[
                                'flex items-center rounded-md border-l-2 px-3 py-2 text-sm transition',
                                isActive(route('profile.show')) || isActive(route('settings.company')) || isActive(route('settings.team'))
                                    ? 'border-l-[#00a86b] bg-emerald-500/15 text-emerald-300'
                                    : 'border-l-transparent text-slate-300 hover:bg-slate-800 hover:text-white',
                            ]"
                        >
                            <Settings class="h-4 w-4 shrink-0" />
                            <span v-if="!collapsed" class="ml-3">Settings</span>
                        </Link>
                        <div v-if="!collapsed" class="ml-9 mt-1 space-y-1">
                            <Link
                                :href="route('profile.show')"
                                class="block rounded px-2 py-1 text-xs text-slate-400 hover:bg-slate-800 hover:text-slate-200"
                            >
                                Profile
                            </Link>
                            <Link
                                :href="route('settings.company')"
                                class="block rounded px-2 py-1 text-xs text-slate-400 hover:bg-slate-800 hover:text-slate-200"
                            >
                                Company
                            </Link>
                            <Link
                                :href="route('settings.team')"
                                class="block rounded px-2 py-1 text-xs text-slate-400 hover:bg-slate-800 hover:text-slate-200"
                            >
                                Team
                            </Link>
                        </div>
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
                    'fixed inset-y-0 left-0 z-[60] w-[260px] bg-[#0f1117] p-4 text-slate-100 shadow-xl transition-transform lg:hidden',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full',
                ]"
            >
                <div class="mb-4 flex items-center justify-between">
                    <span class="font-semibold">Menu</span>
                    <button class="rounded-md p-2 hover:bg-slate-800" @click="mobileOpen = false">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <div class="space-y-2">
                    <Link v-for="item in navItems" :key="`m-${item.label}`" :href="item.href" class="block rounded-md px-3 py-2 text-sm hover:bg-slate-800">
                        {{ item.label }}
                    </Link>
                    <Link :href="route('profile.show')" class="block rounded-md px-3 py-2 text-sm hover:bg-slate-800">Settings</Link>
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
                            isActivePath(route('dashboard')) ? 'text-emerald-700' : 'text-slate-600',
                        ]"
                    >
                        <Home class="h-5 w-5 shrink-0" />
                        <span>Home</span>
                    </Link>
                    <Link
                        :href="route('invoicing.invoices.index')"
                        :class="[
                            'flex min-h-12 flex-col items-center justify-center gap-0.5 pb-2 text-[10px] font-medium',
                            isActivePath(route('invoicing.invoices.index')) ? 'text-emerald-700' : 'text-slate-600',
                        ]"
                    >
                        <FileText class="h-5 w-5 shrink-0" />
                        <span>Invoices</span>
                    </Link>
                    <div class="flex justify-center">
                        <button
                            type="button"
                            class="relative -top-5 flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white shadow-lg ring-4 ring-white"
                            aria-label="Quick add"
                            @click="quickAddOpen = true"
                        >
                            <Plus class="h-7 w-7" />
                        </button>
                    </div>
                    <Link
                        :href="route('reports.profit-loss')"
                        :class="[
                            'flex min-h-12 flex-col items-center justify-center gap-0.5 pb-2 text-[10px] font-medium',
                            isActivePath(route('reports.profit-loss')) ? 'text-emerald-700' : 'text-slate-600',
                        ]"
                    >
                        <ChartColumnBig class="h-5 w-5 shrink-0" />
                        <span>Reports</span>
                    </Link>
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
                            <Receipt class="h-5 w-5 shrink-0 text-emerald-700" />
                            New expense
                        </Link>
                        <Link
                            :href="route('invoicing.invoices.create')"
                            class="flex min-h-12 items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-left text-sm font-medium text-slate-900 active:bg-slate-50"
                            @click="quickAddOpen = false"
                        >
                            <FileText class="h-5 w-5 shrink-0 text-emerald-700" />
                            New invoice
                        </Link>
                        <Link
                            :href="`${route('dashboard')}#outstanding-invoices`"
                            class="flex min-h-12 items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-left text-sm font-medium text-slate-900 active:bg-slate-50"
                            @click="quickAddOpen = false"
                        >
                            <CreditCard class="h-5 w-5 shrink-0 text-emerald-700" />
                            Record payment
                        </Link>
                        <Link
                            :href="route('time.index')"
                            class="flex min-h-12 items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-left text-sm font-medium text-slate-900 active:bg-slate-50"
                            @click="quickAddOpen = false"
                        >
                            <Clock class="h-5 w-5 shrink-0 text-emerald-700" />
                            Start timer
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
