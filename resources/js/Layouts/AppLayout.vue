<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ApplicationMark from '@/Components/ApplicationMark.vue';
import Banner from '@/Components/Banner.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';

type Breadcrumb = { label: string; href?: string };
type NavChild = { label: string; href: string };
type NavSection = { label: string; icon: string; href?: string; children?: NavChild[] };

const props = defineProps<{
    title?: string;
    breadcrumbs?: Breadcrumb[];
}>();

const page = usePage();
const sidebarCollapsed = ref(false);
const mobileSidebarOpen = ref(false);

const navigation: NavSection[] = [
    { label: 'Dashboard', icon: 'home', href: route('dashboard') },
    {
        label: 'Invoicing',
        icon: 'file-text',
        children: [
            { label: 'Invoices', href: '#' },
            { label: 'Quotes', href: '#' },
            { label: 'Clients', href: '#' },
        ],
    },
    {
        label: 'Accounting',
        icon: 'book-open',
        children: [
            { label: 'Transactions', href: '#' },
            { label: 'Chart of Accounts', href: '#' },
            { label: 'Journal', href: '#' },
        ],
    },
    { label: 'Budgeting', icon: 'wallet', href: '#' },
    {
        label: 'Tax',
        icon: 'receipt',
        children: [
            { label: 'VAT Returns', href: '#' },
            { label: 'Tax Periods', href: '#' },
        ],
    },
    {
        label: 'Contracting',
        icon: 'briefcase',
        children: [
            { label: 'Contracts', href: '#' },
            { label: 'Time', href: '#' },
        ],
    },
    { label: 'Reports', icon: 'bar-chart-3', href: '#' },
    { label: 'Settings', icon: 'settings', href: route('profile.show') },
];

const teamFeaturesEnabled = computed(() => Boolean(page.props.jetstream?.hasTeamFeatures));
const allTeams = computed(() => page.props.auth?.user?.all_teams ?? []);
const currentTeam = computed(() => page.props.auth?.user?.current_team);

const switchToTeam = (team: { id: number }) => {
    router.put(route('current-team.update'), { team_id: team.id }, { preserveState: false });
};

const logout = () => router.post(route('logout'));
const toggleSidebar = () => (sidebarCollapsed.value = !sidebarCollapsed.value);
const toggleMobileSidebar = () => (mobileSidebarOpen.value = !mobileSidebarOpen.value);
</script>

<template>
    <div>
        <Head :title="title" />
        <Banner />

        <div class="min-h-screen bg-slate-50 text-slate-900">
            <div class="flex">
                <aside
                    :class="[
                        'hidden border-r border-slate-200 bg-white lg:flex lg:flex-col transition-all duration-200',
                        sidebarCollapsed ? 'lg:w-20' : 'lg:w-72',
                    ]"
                >
                    <div class="flex h-16 items-center border-b border-slate-200 px-4">
                        <Link :href="route('dashboard')" class="flex items-center gap-3">
                            <ApplicationMark class="h-8 w-8 shrink-0" />
                            <span v-if="!sidebarCollapsed" class="text-lg font-semibold">Spennies</span>
                        </Link>
                    </div>

                    <nav class="flex-1 overflow-y-auto p-3">
                        <div v-for="section in navigation" :key="section.label" class="mb-2">
                            <Link
                                v-if="section.href"
                                :href="section.href"
                                class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-100"
                            >
                                <span class="capitalize">{{ section.icon }}</span>
                                <span v-if="!sidebarCollapsed">{{ section.label }}</span>
                            </Link>
                            <div v-else class="rounded-md px-3 py-2 text-sm text-slate-700">
                                <div class="font-medium">{{ section.label }}</div>
                                <div v-if="section.children && !sidebarCollapsed" class="mt-1 space-y-1 pl-3">
                                    <Link
                                        v-for="child in section.children"
                                        :key="child.label"
                                        :href="child.href"
                                        class="block rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100"
                                    >
                                        {{ child.label }}
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </nav>
                </aside>

                <div class="flex min-h-screen flex-1 flex-col">
                    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                        <div class="flex h-16 items-center justify-between px-4 sm:px-6 lg:px-8">
                            <div class="flex items-center gap-2">
                                <button
                                    class="rounded-md border border-slate-200 px-2 py-1 text-sm hover:bg-slate-100 lg:hidden"
                                    @click="toggleMobileSidebar"
                                >
                                    Menu
                                </button>
                                <button
                                    class="hidden rounded-md border border-slate-200 px-2 py-1 text-sm hover:bg-slate-100 lg:inline-flex"
                                    @click="toggleSidebar"
                                >
                                    {{ sidebarCollapsed ? 'Expand' : 'Collapse' }}
                                </button>
                            </div>

                            <div class="flex items-center gap-3">
                                <Dropdown v-if="teamFeaturesEnabled" align="right" width="60">
                                    <template #trigger>
                                        <button
                                            type="button"
                                            class="inline-flex items-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
                                        >
                                            {{ currentTeam?.name }}
                                        </button>
                                    </template>
                                    <template #content>
                                        <div class="w-60">
                                            <DropdownLink :href="route('teams.show', currentTeam)">Team Settings</DropdownLink>
                                            <DropdownLink v-if="$page.props.jetstream.canCreateTeams" :href="route('teams.create')">Create New Team</DropdownLink>
                                            <template v-if="allTeams.length > 1">
                                                <div class="my-2 border-t border-slate-200" />
                                                <template v-for="team in allTeams" :key="team.id">
                                                    <form @submit.prevent="switchToTeam(team)">
                                                        <DropdownLink as="button">{{ team.name }}</DropdownLink>
                                                    </form>
                                                </template>
                                            </template>
                                        </div>
                                    </template>
                                </Dropdown>

                                <button class="rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">
                                    Notifications
                                </button>

                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <button class="rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                            {{ $page.props.auth.user.name }}
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

                    <div
                        v-if="mobileSidebarOpen"
                        class="fixed inset-0 z-40 bg-black/40 lg:hidden"
                        @click="mobileSidebarOpen = false"
                    />
                    <aside
                        :class="[
                            'fixed inset-y-0 left-0 z-50 w-72 transform border-r border-slate-200 bg-white p-4 transition-transform lg:hidden',
                            mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full',
                        ]"
                    >
                        <div class="mb-4 flex items-center justify-between">
                            <span class="font-semibold">Navigation</span>
                            <button class="rounded border px-2 py-1 text-sm" @click="toggleMobileSidebar">Close</button>
                        </div>
                        <div v-for="section in navigation" :key="`mobile-${section.label}`" class="mb-3">
                            <p class="mb-1 text-sm font-medium">{{ section.label }}</p>
                            <div v-if="section.children" class="space-y-1 pl-2">
                                <Link
                                    v-for="child in section.children"
                                    :key="child.label"
                                    :href="child.href"
                                    class="block rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100"
                                >
                                    {{ child.label }}
                                </Link>
                            </div>
                        </div>
                    </aside>

                    <main class="flex-1 p-4 sm:p-6 lg:p-8">
                        <div v-if="props.breadcrumbs?.length" class="mb-4 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                            <template v-for="(crumb, index) in props.breadcrumbs" :key="crumb.label">
                                <Link v-if="crumb.href" :href="crumb.href" class="hover:text-slate-700">{{ crumb.label }}</Link>
                                <span v-else class="text-slate-700">{{ crumb.label }}</span>
                                <span v-if="index < props.breadcrumbs.length - 1">/</span>
                            </template>
                        </div>

                        <header v-if="$slots.header" class="mb-6">
                            <slot name="header" />
                        </header>

                        <slot />
                    </main>
                </div>
            </div>
        </div>
    </div>
</template>
