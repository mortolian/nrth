import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { useAuthStore } from '@/stores/useAuthStore';
import { useTeamStore } from '@/stores/useTeamStore';
import AppButton from '@/Components/AppButton.vue';
import AppInput from '@/Components/AppInput.vue';
import AppSelect from '@/Components/AppSelect.vue';
import AppTable from '@/Components/AppTable.vue';
import AppCard from '@/Components/AppCard.vue';
import AppBadge from '@/Components/AppBadge.vue';
import StatCard from '@/Components/StatCard.vue';
import MoneyDisplay from '@/Components/MoneyDisplay.vue';
import DateDisplay from '@/Components/DateDisplay.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const pinia = createPinia();
        const app = createApp({ render: () => h(App, props) })
            .use(pinia)
            .use(plugin)
            .use(ZiggyVue)
            .component('AppButton', AppButton)
            .component('AppInput', AppInput)
            .component('AppSelect', AppSelect)
            .component('AppTable', AppTable)
            .component('AppCard', AppCard)
            .component('AppBadge', AppBadge)
            .component('StatCard', StatCard)
            .component('MoneyDisplay', MoneyDisplay)
            .component('DateDisplay', DateDisplay)
            .component('PageHeader', PageHeader)
            .component('EmptyState', EmptyState)
            .component('ConfirmDialog', ConfirmDialog);

        const authStore = useAuthStore(pinia);
        const teamStore = useTeamStore(pinia);
        authStore.setUser(props.initialPage.props?.auth?.user ?? {});
        teamStore.setTeam({
            id: props.initialPage.props?.auth?.user?.current_team?.id ?? null,
            name: props.initialPage.props?.auth?.user?.current_team?.name ?? '',
            plan: props.initialPage.props?.auth?.user?.current_team?.plan ?? null,
            features: props.initialPage.props?.auth?.user?.current_team?.features ?? {},
        });

        return app.mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
