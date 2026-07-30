<script setup>
import { nextTick, reactive, ref } from 'vue';
import AppButton from './AppButton.vue';
import DialogModal from './DialogModal.vue';
import InputError from './InputError.vue';
import InputLabel from './InputLabel.vue';
import TextInput from './TextInput.vue';

const emit = defineEmits(['confirmed']);

defineProps({
    title: {
        type: String,
        default: 'Confirm your password',
    },
    content: {
        type: String,
        default: 'For your security, confirm your password to continue.',
    },
    button: {
        type: String,
        default: 'Confirm',
    },
});

const confirmingPassword = ref(false);

const form = reactive({
    password: '',
    error: '',
    processing: false,
});

const passwordInput = ref(null);

const startConfirmingPassword = () => {
    axios.get(route('password.confirmation')).then((response) => {
        if (response.data.confirmed) {
            emit('confirmed');
        } else {
            confirmingPassword.value = true;

            setTimeout(() => passwordInput.value.focus(), 250);
        }
    });
};

const confirmPassword = () => {
    form.processing = true;

    axios.post(route('password.confirm'), {
        password: form.password,
    }).then(() => {
        form.processing = false;

        closeModal();
        nextTick().then(() => emit('confirmed'));
    }).catch((error) => {
        form.processing = false;
        form.error = error.response?.data?.errors?.password?.[0] || 'Incorrect password.';
        passwordInput.value.focus();
    });
};

const closeModal = () => {
    confirmingPassword.value = false;
    form.password = '';
    form.error = '';
};
</script>

<template>
    <span>
        <span @click="startConfirmingPassword">
            <slot />
        </span>

        <DialogModal :show="confirmingPassword" max-width="md" @close="closeModal">
            <template #title>
                {{ title }}
            </template>

            <template #content>
                <p class="text-sm text-slate-600">
                    {{ content }}
                </p>

                <div class="mt-4">
                    <InputLabel for="confirming_user_password" value="Password" />
                    <TextInput
                        id="confirming_user_password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-full"
                        placeholder="Your current password"
                        autocomplete="current-password"
                        @keyup.enter="confirmPassword"
                    />

                    <InputError :message="form.error" class="mt-2" />
                </div>
            </template>

            <template #footer>
                <AppButton variant="ghost" @click="closeModal">
                    Cancel
                </AppButton>

                <AppButton
                    class="ms-3"
                    variant="primary"
                    :loading="form.processing"
                    :disabled="form.processing || ! form.password"
                    @click="confirmPassword"
                >
                    {{ form.processing ? 'Confirming…' : button }}
                </AppButton>
            </template>
        </DialogModal>
    </span>
</template>
