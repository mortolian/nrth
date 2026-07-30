<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, ShieldCheck } from 'lucide-vue-next';
import ActionSection from '@/Components/ActionSection.vue';
import AppButton from '@/Components/AppButton.vue';
import ConfirmsPassword from '@/Components/ConfirmsPassword.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    requiresConfirmation: Boolean,
});

const page = usePage();
const toast = useToast();

const setupOpen = ref(false);
const setupStep = ref('password'); // password | scan | recovery
const enabling = ref(false);
const confirming = ref(false);
const disabling = ref(false);
const loadingCodes = ref(false);
const qrCode = ref(null);
const setupKey = ref(null);
const recoveryCodes = ref([]);
const recoveryModalOpen = ref(false);
const disableModalOpen = ref(false);
const copiedKey = ref(false);
const copiedCodes = ref(false);

const passwordForm = ref({
    password: '',
    error: '',
    processing: false,
});
const passwordInput = ref(null);
const codeInput = ref(null);

const confirmationForm = useForm({
    code: '',
});

const disableForm = useForm({
    password: '',
});
const disablePasswordInput = ref(null);

/** Secret exists (Jetstream). May still need OTP confirmation. */
const twoFactorSecretPresent = computed(
    () => Boolean(page.props.auth.user?.two_factor_enabled),
);

/** Fully usable 2FA — not mid-enable / mid-confirm. */
const twoFactorEnabled = computed(
    () => twoFactorSecretPresent.value && ! enabling.value && ! confirming.value,
);

const setupSteps = computed(() => {
    const steps = [
        { id: 'password', label: 'Password' },
        { id: 'scan', label: 'Authenticator' },
    ];

    if (props.requiresConfirmation || recoveryCodes.value.length > 0) {
        steps.push({ id: 'recovery', label: 'Recovery codes' });
    }

    return steps;
});

watch(twoFactorSecretPresent, (present) => {
    if (! present && ! setupOpen.value) {
        confirmationForm.reset();
        confirmationForm.clearErrors();
        recoveryCodes.value = [];
        qrCode.value = null;
        setupKey.value = null;
    }
});

const resetPasswordForm = () => {
    passwordForm.value = {
        password: '',
        error: '',
        processing: false,
    };
};

const openSetup = () => {
    setupOpen.value = true;
    setupStep.value = 'password';
    confirming.value = false;
    qrCode.value = null;
    setupKey.value = null;
    recoveryCodes.value = [];
    confirmationForm.reset();
    confirmationForm.clearErrors();
    resetPasswordForm();
    copiedKey.value = false;
    copiedCodes.value = false;

    nextTick(() => {
        setTimeout(() => passwordInput.value?.focus?.(), 200);
    });
};

const closeSetup = async ({ cancelPending = true } = {}) => {
    const shouldCancelPending = cancelPending && confirming.value && props.requiresConfirmation;

    setupOpen.value = false;

    if (shouldCancelPending) {
        await cancelPendingSetup();
    }

    setupStep.value = 'password';
    confirming.value = false;
    enabling.value = false;
    qrCode.value = null;
    setupKey.value = null;
    confirmationForm.reset();
    confirmationForm.clearErrors();
    resetPasswordForm();
};

const cancelPendingSetup = () => {
    return new Promise((resolve) => {
        router.delete(route('two-factor.disable'), {
            preserveScroll: true,
            onFinish: () => {
                confirming.value = false;
                resolve();
            },
        });
    });
};

const confirmPasswordForSetup = () => {
    passwordForm.value.processing = true;
    passwordForm.value.error = '';

    axios.post(route('password.confirm'), {
        password: passwordForm.value.password,
    }).then(() => {
        passwordForm.value.processing = false;
        enableTwoFactorAuthentication();
    }).catch((error) => {
        passwordForm.value.processing = false;
        passwordForm.value.error = error.response?.data?.errors?.password?.[0] || 'Password confirmation failed.';
        passwordInput.value?.focus?.();
    });
};

const fetchSetupPayload = () => Promise.all([
    showQrCode(),
    showSetupKey(),
    showRecoveryCodes(),
]);

const enableTwoFactorAuthentication = () => {
    enabling.value = true;

    router.post(route('two-factor.enable'), {}, {
        preserveScroll: true,
        onSuccess: async () => {
            try {
                await fetchSetupPayload();
                confirming.value = props.requiresConfirmation;
                setupStep.value = 'scan';
                await nextTick();
                setTimeout(() => codeInput.value?.focus?.(), 200);
            } catch {
                toast.error('Could not load authenticator details. Please try again.');
                await cancelPendingSetup();
                closeSetup({ cancelPending: false });
            }
        },
        onError: () => {
            toast.error('Could not enable two-factor authentication.');
        },
        onFinish: () => {
            enabling.value = false;
        },
    });
};

const showQrCode = () => axios.get(route('two-factor.qr-code')).then((response) => {
    qrCode.value = response.data.svg;
});

const showSetupKey = () => axios.get(route('two-factor.secret-key')).then((response) => {
    setupKey.value = response.data.secretKey;
});

const showRecoveryCodes = () => axios.get(route('two-factor.recovery-codes')).then((response) => {
    recoveryCodes.value = response.data;
});

const confirmTwoFactorAuthentication = () => {
    confirmationForm.post(route('two-factor.confirm'), {
        errorBag: 'confirmTwoFactorAuthentication',
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            confirming.value = false;
            qrCode.value = null;
            setupKey.value = null;
            setupStep.value = 'recovery';
            toast.success('Two-factor authentication enabled.');
        },
        onError: () => {
            if (! confirmationForm.hasErrors) {
                toast.error('Invalid authentication code.');
            }
        },
    });
};

const finishSetup = () => {
    setupOpen.value = false;
    setupStep.value = 'password';
    recoveryCodes.value = [];
    resetPasswordForm();
};

const openRecoveryModal = async () => {
    loadingCodes.value = true;
    try {
        await showRecoveryCodes();
        recoveryModalOpen.value = true;
        copiedCodes.value = false;
    } catch {
        toast.error('Could not load recovery codes.');
    } finally {
        loadingCodes.value = false;
    }
};

const regenerateRecoveryCodes = async () => {
    loadingCodes.value = true;
    try {
        await axios.post(route('two-factor.recovery-codes'));
        await showRecoveryCodes();
        recoveryModalOpen.value = true;
        copiedCodes.value = false;
        toast.success('Recovery codes regenerated.');
    } catch {
        toast.error('Could not regenerate recovery codes.');
    } finally {
        loadingCodes.value = false;
    }
};

const openDisableModal = () => {
    disableModalOpen.value = true;
    disableForm.reset();
    disableForm.clearErrors();
    nextTick(() => {
        setTimeout(() => disablePasswordInput.value?.focus?.(), 200);
    });
};

const closeDisableModal = () => {
    disableModalOpen.value = false;
    disableForm.reset();
    disableForm.clearErrors();
};

const disableTwoFactorAuthentication = () => {
    disabling.value = true;

    axios.post(route('password.confirm'), {
        password: disableForm.password,
    }).then(() => {
        router.delete(route('two-factor.disable'), {
            preserveScroll: true,
            onSuccess: () => {
                disabling.value = false;
                confirming.value = false;
                closeDisableModal();
                toast.success('Two-factor authentication disabled.');
            },
            onError: () => {
                disabling.value = false;
                toast.error('Could not disable two-factor authentication.');
            },
        });
    }).catch((error) => {
        disabling.value = false;
        disableForm.setError('password', error.response?.data?.errors?.password?.[0] || 'Incorrect password.');
        disablePasswordInput.value?.focus?.();
    });
};

const copyText = async (text, kind) => {
    try {
        await navigator.clipboard.writeText(text);
        if (kind === 'key') {
            copiedKey.value = true;
            setTimeout(() => { copiedKey.value = false; }, 2000);
        } else {
            copiedCodes.value = true;
            setTimeout(() => { copiedCodes.value = false; }, 2000);
        }
        toast.success(kind === 'key' ? 'Setup key copied.' : 'Recovery codes copied.');
    } catch {
        toast.error('Could not copy to clipboard.');
    }
};

const stepIndex = (id) => setupSteps.value.findIndex((step) => step.id === id);
const isStepComplete = (id) => stepIndex(id) < stepIndex(setupStep.value);
const isStepCurrent = (id) => setupStep.value === id;
</script>

<template>
    <ActionSection>
        <template #title>
            Two-factor authentication
        </template>

        <template #description>
            Add a second step when signing in with a code from an authenticator app.
        </template>

        <template #content>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="max-w-xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-semibold text-slate-900">
                            {{ twoFactorEnabled ? 'Enabled on this account' : 'Not enabled yet' }}
                        </p>
                        <AppBadge :variant="twoFactorEnabled ? 'success' : 'neutral'">
                            {{ twoFactorEnabled ? 'Protected' : 'Optional' }}
                        </AppBadge>
                    </div>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        After you sign in with your password, you’ll enter a one-time code from an app such as
                        Google Authenticator, 1Password, or Authy. Keep your recovery codes somewhere safe.
                    </p>
                </div>

                <div v-if="! twoFactorEnabled && ! setupOpen" class="shrink-0">
                    <AppButton variant="primary" @click="openSetup">
                        Enable two-factor
                    </AppButton>
                </div>
            </div>

            <div v-if="twoFactorEnabled" class="mt-5 flex flex-wrap gap-2">
                <ConfirmsPassword
                    title="Show recovery codes"
                    content="Confirm your password to view your recovery codes."
                    button="Continue"
                    @confirmed="openRecoveryModal"
                >
                    <AppButton variant="secondary" :loading="loadingCodes" :disabled="loadingCodes">
                        Show recovery codes
                    </AppButton>
                </ConfirmsPassword>

                <ConfirmsPassword
                    title="Regenerate recovery codes"
                    content="Confirm your password to generate a new set of recovery codes. Your old codes will stop working."
                    button="Continue"
                    @confirmed="regenerateRecoveryCodes"
                >
                    <AppButton variant="secondary" :loading="loadingCodes" :disabled="loadingCodes">
                        Regenerate codes
                    </AppButton>
                </ConfirmsPassword>

                <AppButton
                    variant="secondary"
                    class="!border-rose-300 !text-rose-700 hover:!bg-rose-50"
                    @click="openDisableModal"
                >
                    Disable
                </AppButton>
            </div>
        </template>
    </ActionSection>

    <!-- Guided enable wizard -->
    <DialogModal :show="setupOpen" max-width="lg" :closeable="setupStep === 'password'" @close="closeSetup()">
        <template #title>
            <div class="flex items-center gap-2">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                    <ShieldCheck class="h-4 w-4" />
                </span>
                <span>Set up two-factor authentication</span>
            </div>
        </template>

        <template #content>
            <ol class="mb-5 flex flex-wrap gap-2">
                <li
                    v-for="(step, index) in setupSteps"
                    :key="step.id"
                    class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-medium"
                    :class="{
                        'bg-brand-100 text-brand-800': isStepCurrent(step.id),
                        'bg-slate-100 text-slate-500': ! isStepCurrent(step.id) && ! isStepComplete(step.id),
                        'bg-slate-900 text-white': isStepComplete(step.id),
                    }"
                >
                    <span
                        class="inline-flex h-4 w-4 items-center justify-center rounded-full text-[10px]"
                        :class="isStepComplete(step.id) ? 'bg-white/20' : isStepCurrent(step.id) ? 'bg-brand-600 text-white' : 'bg-slate-200 text-slate-600'"
                    >
                        <Check v-if="isStepComplete(step.id)" class="h-3 w-3" />
                        <template v-else>{{ index + 1 }}</template>
                    </span>
                    {{ step.label }}
                </li>
            </ol>

            <div v-if="setupStep === 'password'" class="space-y-4">
                <p class="text-sm leading-relaxed text-slate-600">
                    Confirm your account password to begin. You’ll then scan a QR code and verify a code from your authenticator app.
                </p>
                <div>
                    <InputLabel for="two_factor_setup_password" value="Password" />
                    <TextInput
                        id="two_factor_setup_password"
                        ref="passwordInput"
                        v-model="passwordForm.password"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                        placeholder="Your current password"
                        @keyup.enter="confirmPasswordForSetup"
                    />
                    <InputError :message="passwordForm.error" class="mt-2" />
                </div>
            </div>

            <div v-else-if="setupStep === 'scan'" class="space-y-5">
                <p class="text-sm leading-relaxed text-slate-600">
                    Open your authenticator app, scan this QR code, then enter the 6-digit code it shows to finish setup.
                </p>

                <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
                    <div
                        v-if="qrCode"
                        class="inline-flex shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white p-3 shadow-sm"
                        v-html="qrCode"
                    />
                    <div class="min-w-0 flex-1 space-y-4">
                        <div v-if="setupKey" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Setup key</p>
                                <AppButton
                                    variant="ghost"
                                    size="sm"
                                    class="!px-2"
                                    @click="copyText(setupKey, 'key')"
                                >
                                    <Check v-if="copiedKey" class="mr-1 h-3.5 w-3.5" />
                                    <Copy v-else class="mr-1 h-3.5 w-3.5" />
                                    {{ copiedKey ? 'Copied' : 'Copy' }}
                                </AppButton>
                            </div>
                            <p class="mt-1 break-all font-mono text-sm text-slate-800">{{ setupKey }}</p>
                            <p class="mt-1 text-xs text-slate-500">Use this if you can’t scan the QR code.</p>
                        </div>

                        <div v-if="requiresConfirmation">
                            <InputLabel for="two_factor_code" value="Authentication code" />
                            <TextInput
                                id="two_factor_code"
                                ref="codeInput"
                                v-model="confirmationForm.code"
                                type="text"
                                name="code"
                                class="mt-1 block w-full tracking-[0.2em]"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                placeholder="000000"
                                @keyup.enter="confirmTwoFactorAuthentication"
                            />
                            <InputError :message="confirmationForm.errors.code" class="mt-2" />
                        </div>
                    </div>
                </div>
            </div>

            <div v-else-if="setupStep === 'recovery'" class="space-y-4">
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-900">
                    Store these recovery codes in a password manager. Each code can be used once if you lose access to your authenticator.
                </div>

                <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-4">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <KeyRound class="h-4 w-4 text-slate-500" />
                            Recovery codes
                        </div>
                        <AppButton
                            variant="ghost"
                            size="sm"
                            class="!px-2"
                            @click="copyText(recoveryCodes.join('\n'), 'codes')"
                        >
                            <Check v-if="copiedCodes" class="mr-1 h-3.5 w-3.5" />
                            <Copy v-else class="mr-1 h-3.5 w-3.5" />
                            {{ copiedCodes ? 'Copied' : 'Copy all' }}
                        </AppButton>
                    </div>
                    <div class="grid grid-cols-1 gap-1.5 font-mono text-sm text-slate-800 sm:grid-cols-2">
                        <div v-for="code in recoveryCodes" :key="code" class="rounded-md bg-white px-3 py-1.5 border border-slate-200">
                            {{ code }}
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <template #footer>
            <template v-if="setupStep === 'password'">
                <AppButton variant="ghost" @click="closeSetup()">
                    Cancel
                </AppButton>
                <AppButton
                    class="ms-3"
                    variant="primary"
                    :loading="passwordForm.processing || enabling"
                    :disabled="passwordForm.processing || enabling || ! passwordForm.password"
                    @click="confirmPasswordForSetup"
                >
                    {{ enabling ? 'Starting…' : 'Continue' }}
                </AppButton>
            </template>

            <template v-else-if="setupStep === 'scan'">
                <AppButton variant="ghost" :disabled="confirmationForm.processing || disabling" @click="closeSetup()">
                    Cancel
                </AppButton>
                <AppButton
                    v-if="requiresConfirmation"
                    class="ms-3"
                    variant="primary"
                    :loading="confirmationForm.processing"
                    :disabled="confirmationForm.processing || ! confirmationForm.code"
                    @click="confirmTwoFactorAuthentication"
                >
                    Verify and continue
                </AppButton>
                <AppButton
                    v-else
                    class="ms-3"
                    variant="primary"
                    @click="setupStep = 'recovery'"
                >
                    Continue
                </AppButton>
            </template>

            <template v-else>
                <AppButton variant="primary" @click="finishSetup">
                    Done
                </AppButton>
            </template>
        </template>
    </DialogModal>

    <!-- Recovery codes viewer -->
    <DialogModal :show="recoveryModalOpen" max-width="lg" @close="recoveryModalOpen = false">
        <template #title>
            Recovery codes
        </template>
        <template #content>
            <p class="mb-4 text-sm text-slate-600">
                Keep these codes somewhere safe. Each one works once if you can’t use your authenticator app.
            </p>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-4">
                <div class="mb-3 flex justify-end">
                    <AppButton
                        variant="ghost"
                        size="sm"
                        class="!px-2"
                        @click="copyText(recoveryCodes.join('\n'), 'codes')"
                    >
                        <Check v-if="copiedCodes" class="mr-1 h-3.5 w-3.5" />
                        <Copy v-else class="mr-1 h-3.5 w-3.5" />
                        {{ copiedCodes ? 'Copied' : 'Copy all' }}
                    </AppButton>
                </div>
                <div class="grid grid-cols-1 gap-1.5 font-mono text-sm text-slate-800 sm:grid-cols-2">
                    <div v-for="code in recoveryCodes" :key="code" class="rounded-md border border-slate-200 bg-white px-3 py-1.5">
                        {{ code }}
                    </div>
                </div>
            </div>
        </template>
        <template #footer>
            <AppButton variant="primary" @click="recoveryModalOpen = false">
                Close
            </AppButton>
        </template>
    </DialogModal>

    <!-- Disable confirm -->
    <DialogModal :show="disableModalOpen" max-width="md" @close="closeDisableModal">
        <template #title>
            Disable two-factor authentication?
        </template>
        <template #content>
            <p class="text-sm text-slate-600">
                You’ll sign in with your password only until you set it up again. Enter your password to confirm.
            </p>
            <div class="mt-4">
                <InputLabel for="disable_two_factor_password" value="Password" />
                <TextInput
                    id="disable_two_factor_password"
                    ref="disablePasswordInput"
                    v-model="disableForm.password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    @keyup.enter="disableTwoFactorAuthentication"
                />
                <InputError :message="disableForm.errors.password" class="mt-2" />
            </div>
        </template>
        <template #footer>
            <AppButton variant="ghost" @click="closeDisableModal">
                Cancel
            </AppButton>
            <AppButton
                class="ms-3"
                variant="danger"
                :loading="disabling"
                :disabled="disabling || ! disableForm.password"
                @click="disableTwoFactorAuthentication"
            >
                {{ disabling ? 'Disabling…' : 'Disable' }}
            </AppButton>
        </template>
    </DialogModal>
</template>
