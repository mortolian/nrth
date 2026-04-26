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
    FileText,
    FolderKanban,
    Home,
    Landmark,
    Menu,
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
        href: '#',
        icon: BookOpen,
        group: [{ title: 'Accounting', items: [{ label: 'Transactions', href: '#' }, { label: 'Journal', href: '#' }, { label: 'Chart of Accounts', href: '#' }] }],
    },
    { label: 'Planning', href: '#', icon: FolderKanban, group: [{ title: 'Planning', items: [{ label: 'Budgets', href: '#' }] }] },
    {
        label: 'Tax',
        href: '#',
        icon: Calculator,
        group: [{ title: 'Tax', items: [{ label: 'VAT Returns', href: '#' }, { label: 'Tax Periods', href: '#' }, { label: 'Documents', href: '#' }] }],
    },
    {
        label: 'Contracting',
        href: '#',
        icon: Briefcase,
        group: [{ title: 'Contracting', items: [{ label: 'Contracts', href: '#' }, { label: 'Time Tracking', href: '#' }] }],
    },
    {
        label: 'Reports',
        href: '#',
        icon: ChartColumnBig,
        group: [{ title: 'Reports', items: [{ label: 'P&L', href: '#' }, { label: 'Balance Sheet', href: '#' }, { label: 'Cash Flow', href: '#' }, { label: 'Trial Balance', href: '#' }] }],
    },
];

const bottomTabs = computed(() => [
    { label: 'Home', href: route('dashboard'), icon: Home },
    { label: 'Invoices', href: route('invoicing.invoices.index'), icon: FileText },
    { label: 'Transact', href: '#', icon: BookOpen },
    { label: 'Tax', href: '#', icon: Calculator },
    { label: 'Reports', href: '#', icon: ChartColumnBig },
]);

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
const isActive = (href: string) => href !== '#' && currentPath.value === href;

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
                    <Link
                        :href="route('profile.show')"
                        :class="[
                            'flex items-center rounded-md border-l-2 px-3 py-2 text-sm transition',
                            isActive(route('profile.show'))
                                ? 'border-l-[#00a86b] bg-emerald-500/15 text-emerald-300'
                                : 'border-l-transparent text-slate-300 hover:bg-slate-800 hover:text-white',
                        ]"
                    >
                        <Settings class="h-4 w-4 shrink-0" />
                        <span v-if="!collapsed" class="ml-3">Settings</span>
                    </Link>
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

                <main class="pb-20 lg:pb-8">
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

            <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white lg:hidden">
                <div class="grid grid-cols-5">
                    <Link
                        v-for="item in bottomTabs"
                        :key="`tab-${item.label}`"
                        :href="item.href"
                        class="flex flex-col items-center justify-center gap-1 py-2 text-[11px] text-slate-600"
                    >
                        <component :is="item.icon" class="h-4 w-4" />
                        <span>{{ item.label }}</span>
                    </Link>
                </div>
            </nav>
        </div>

        <CommandPalette v-model:open="commandPaletteOpen" :data="commandPaletteData" />
    </div>
</template>
