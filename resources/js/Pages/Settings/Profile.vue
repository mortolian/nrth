<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionBorder from '@/Components/SectionBorder.vue';
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm.vue';
import LogoutOtherBrowserSessionsForm from '@/Pages/Profile/Partials/LogoutOtherBrowserSessionsForm.vue';
import TwoFactorAuthenticationForm from '@/Pages/Profile/Partials/TwoFactorAuthenticationForm.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm.vue';
import ProfilePreferencesForm from '@/Pages/Settings/Partials/ProfilePreferencesForm.vue';

type Preferences = {
    notify_invoice_overdue: boolean;
    notify_vat_due: boolean;
    notify_provisional_tax: boolean;
    date_format: string;
    theme: string;
};

defineProps<{
    confirmsTwoFactorAuthentication: boolean;
    sessions: Array<Record<string, unknown>>;
    preferences: Preferences;
}>();
</script>

<template>
    <AppLayout
        title="Profile"
        :breadcrumbs="[
            { label: 'Settings' },
            { label: 'Profile' },
        ]"
    >
        <PageHeader title="Profile & security" subtitle="Your account, password, two-factor authentication, and preferences" />

        <div class="mx-auto mt-6 max-w-4xl space-y-8">
            <div v-if="$page.props.jetstream.canUpdateProfileInformation">
                <UpdateProfileInformationForm :user="$page.props.auth.user" />
                <SectionBorder />
            </div>

            <ProfilePreferencesForm :preferences="preferences" />

            <div v-if="$page.props.jetstream.canUpdatePassword">
                <SectionBorder />
                <UpdatePasswordForm class="mt-10 sm:mt-0" />
                <SectionBorder />
            </div>

            <div v-if="$page.props.jetstream.canManageTwoFactorAuthentication">
                <TwoFactorAuthenticationForm
                    :requires-confirmation="confirmsTwoFactorAuthentication"
                    class="mt-10 sm:mt-0"
                />
                <SectionBorder />
            </div>

            <LogoutOtherBrowserSessionsForm :sessions="sessions" class="mt-10 sm:mt-0" />

            <template v-if="$page.props.jetstream.hasAccountDeletionFeatures">
                <SectionBorder />
                <DeleteUserForm class="mt-10 sm:mt-0" />
            </template>
        </div>
    </AppLayout>
</template>
