<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    setTimeout(() => passwordInput.value.focus(), 250);
};

const deleteUser = () => {
    form.delete(route('current-user.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.reset();
};
</script>

<template>
    <section class="rounded-xl border border-rose-200 bg-rose-50/40 p-4 md:p-5">
        <h4 class="text-sm font-semibold text-slate-900">
            Delete account
        </h4>
        <p class="mt-0.5 text-xs text-slate-500">
            Permanently remove your user and personal access. Teams and data owned by others are not deleted automatically.
        </p>

        <div class="mt-4 max-w-2xl text-sm leading-relaxed text-slate-600">
            Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your
            account, please download any data or information that you wish to retain.
        </div>

        <div class="mt-4">
            <AppButton variant="secondary" class="!border-rose-300 !text-rose-700 hover:!bg-rose-50" @click="confirmUserDeletion">
                Delete account
            </AppButton>
        </div>

        <DialogModal :show="confirmingUserDeletion" @close="closeModal">
            <template #title>
                Delete account
            </template>

            <template #content>
                Are you sure you want to delete your account? Once your account is deleted, all of its resources and data
                will be permanently deleted. Please enter your password to confirm you would like to permanently delete
                your account.

                <div class="mt-4">
                    <TextInput
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-3/4"
                        placeholder="Password"
                        autocomplete="current-password"
                        @keyup.enter="deleteUser"
                    />

                    <InputError :message="form.errors.password" class="mt-2" />
                </div>
            </template>

            <template #footer>
                <AppButton variant="ghost" @click="closeModal">
                    Cancel
                </AppButton>

                <AppButton
                    variant="primary"
                    class="ms-3 !bg-rose-600"
                    :loading="form.processing"
                    @click="deleteUser"
                >
                    {{ form.processing ? 'Deleting…' : 'Delete account' }}
                </AppButton>
            </template>
        </DialogModal>
    </section>
</template>
