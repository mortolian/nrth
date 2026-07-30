<script setup>
import { nextTick, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ShieldCheck } from 'lucide-vue-next';
import AppButton from '@/Components/AppButton.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';

const recovery = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

const recoveryCodeInput = ref(null);
const codeInput = ref(null);

const toggleRecovery = async () => {
    recovery.value = ! recovery.value;

    await nextTick();

    if (recovery.value) {
        recoveryCodeInput.value.focus();
        form.code = '';
        form.clearErrors('code');
    } else {
        codeInput.value.focus();
        form.recovery_code = '';
        form.clearErrors('recovery_code');
    }
};

const submit = () => {
    form.post(route('two-factor.login'));
};
</script>

<template>
    <Head title="Two-factor authentication" />

    <div
        class="relative flex min-h-screen flex-col justify-center overflow-hidden px-6 py-12"
        style="background: linear-gradient(180deg, #d9d5cc 0%, #ebe8e1 40%, #f3f1ec 70%, #f7f6f3 100%);"
    >
        <div
            class="pointer-events-none absolute inset-0 opacity-[0.45]"
            aria-hidden="true"
            style="background-image: radial-gradient(circle at 1px 1px, rgba(120, 110, 95, 0.18) 1px, transparent 0); background-size: 20px 20px;"
        />

        <div class="challenge-enter relative z-10 mx-auto w-full max-w-[22rem]">
            <div class="mb-10 flex flex-col items-center text-center">
                <AuthenticationCardLogo :size="48" />
            </div>

            <div
                class="rounded-2xl border border-stone-200/90 bg-white px-6 py-7 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_12px_40px_-24px_rgba(15,23,42,0.18)]"
            >
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                        <ShieldCheck class="h-[1.125rem] w-[1.125rem]" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold tracking-tight text-slate-900">
                            {{ recovery ? 'Recovery code' : 'Authenticator code' }}
                        </h2>
                        <p class="mt-1 text-sm leading-relaxed text-slate-600">
                            <template v-if="! recovery">
                                Enter the 6-digit code from your authenticator app to finish signing in.
                            </template>
                            <template v-else>
                                Enter one of your one-time recovery codes. Each code can only be used once.
                            </template>
                        </p>
                    </div>
                </div>

                <form class="mt-6 space-y-5" @submit.prevent="submit">
                    <div v-if="! recovery">
                        <InputLabel for="code" value="Authentication code" />
                        <TextInput
                            id="code"
                            ref="codeInput"
                            v-model="form.code"
                            type="text"
                            inputmode="numeric"
                            class="mt-1 block w-full text-center text-lg tracking-[0.35em]"
                            autofocus
                            autocomplete="one-time-code"
                            placeholder="000000"
                        />
                        <InputError class="mt-2" :message="form.errors.code" />
                    </div>

                    <div v-else>
                        <InputLabel for="recovery_code" value="Recovery code" />
                        <TextInput
                            id="recovery_code"
                            ref="recoveryCodeInput"
                            v-model="form.recovery_code"
                            type="text"
                            class="mt-1 block w-full font-mono text-sm tracking-wide"
                            autocomplete="one-time-code"
                            placeholder="xxxx-xxxx"
                        />
                        <InputError class="mt-2" :message="form.errors.recovery_code" />
                    </div>

                    <div class="flex flex-col gap-3">
                        <AppButton
                            type="submit"
                            variant="primary"
                            class="w-full"
                            :loading="form.processing"
                            :disabled="form.processing"
                        >
                            {{ form.processing ? 'Verifying…' : 'Continue' }}
                        </AppButton>

                        <button
                            type="button"
                            class="text-center text-sm font-medium text-brand-700 underline decoration-brand-300 underline-offset-2 hover:text-brand-800"
                            @click.prevent="toggleRecovery"
                        >
                            {{ recovery ? 'Use an authenticator code instead' : 'Use a recovery code instead' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes challenge-fade-up {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.challenge-enter {
    animation: challenge-fade-up 0.5s ease-out both;
}

@media (prefers-reduced-motion: reduce) {
    .challenge-enter {
        animation: none;
    }
}
</style>
