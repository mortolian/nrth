<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm.vue';
import LogoutOtherBrowserSessionsForm from '@/Pages/Profile/Partials/LogoutOtherBrowserSessionsForm.vue';
import TwoFactorAuthenticationForm from '@/Pages/Profile/Partials/TwoFactorAuthenticationForm.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm.vue';

defineProps({
    confirmsTwoFactorAuthentication: Boolean,
    sessions: Array,
});
</script>

<template>
    <AppLayout
        title="Profile"
        :breadcrumbs="[
            { label: 'Home', href: route('dashboard') },
            { label: 'Profile' },
        ]"
    >
        <PageHeader
            title="Profile"
            subtitle="Your personal sign-in, password, and security settings. Company and team workspaces are configured under Settings."
        />

        <div class="mt-5 space-y-6">
            <AppCard>
                <h3 class="text-base font-semibold text-slate-900">Account</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-slate-500">
                    Update how you appear in the app, how you sign in, and how to recover access if you lose a device.
                </p>

                <div class="mt-6 space-y-5">
                    <UpdateProfileInformationForm v-if="$page.props.jetstream.canUpdateProfileInformation" :user="$page.props.auth.user" />

                    <UpdatePasswordForm v-if="$page.props.jetstream.canUpdatePassword" />

                    <TwoFactorAuthenticationForm
                        v-if="$page.props.jetstream.canManageTwoFactorAuthentication"
                        :requires-confirmation="confirmsTwoFactorAuthentication"
                    />

                    <LogoutOtherBrowserSessionsForm :sessions="sessions" />

                    <DeleteUserForm v-if="$page.props.jetstream.hasAccountDeletionFeatures" />
                </div>
            </AppCard>
        </div>
    </AppLayout>
</template>
