<script setup lang="ts">
import SettingsShell from '@/Components/SettingsShell.vue';
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm.vue';
import LogoutOtherBrowserSessionsForm from '@/Pages/Profile/Partials/LogoutOtherBrowserSessionsForm.vue';
import TwoFactorAuthenticationForm from '@/Pages/Profile/Partials/TwoFactorAuthenticationForm.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm.vue';
import ProfilePreferencesForm from '@/Pages/Settings/Partials/ProfilePreferencesForm.vue';
import LeaveCurrentBusinessForm from '@/Pages/Settings/Partials/LeaveCurrentBusinessForm.vue';

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
    <SettingsShell section="profile">
        <div class="space-y-6">
            <div v-if="$page.props.jetstream.canUpdateProfileInformation">
                <UpdateProfileInformationForm :user="$page.props.auth.user" />
            </div>

            <ProfilePreferencesForm :preferences="preferences" />

            <div v-if="$page.props.jetstream.canUpdatePassword">
                <UpdatePasswordForm />
            </div>

            <div v-if="$page.props.jetstream.canManageTwoFactorAuthentication">
                <TwoFactorAuthenticationForm
                    :requires-confirmation="confirmsTwoFactorAuthentication"
                />
            </div>

            <LogoutOtherBrowserSessionsForm :sessions="sessions" />

            <LeaveCurrentBusinessForm />

            <div v-if="$page.props.jetstream.hasAccountDeletionFeatures">
                <DeleteUserForm />
            </div>
        </div>
    </SettingsShell>
</template>
