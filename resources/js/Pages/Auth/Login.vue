<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import AppVersionLabel from '@/Components/AppVersionLabel.vue';

const props = defineProps({
    canResetPassword: Boolean,
    status: String,
    invitation: {
        type: Object,
        default: null,
    },
});

const form = useForm({
    email: props.invitation?.email ?? '',
    password: '',
    remember: false,
});

const submit = () => {
    form.transform(data => ({
        ...data,
        remember: form.remember ? 'on' : '',
    })).post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Log in" />

    <div
        class="relative flex min-h-screen flex-col justify-center overflow-hidden px-6 py-12"
        style="background: linear-gradient(180deg, #d9d5cc 0%, #ebe8e1 40%, #f3f1ec 70%, #f7f6f3 100%);"
    >
        <div
            class="pointer-events-none absolute inset-0 opacity-[0.45]"
            aria-hidden="true"
            style="background-image: radial-gradient(circle at 1px 1px, rgba(120, 110, 95, 0.18) 1px, transparent 0); background-size: 20px 20px;"
        />

        <div class="login-form-enter relative z-10 mx-auto w-full max-w-[22rem]">
            <div class="mb-10 flex flex-col items-center text-center">
                <AuthenticationCardLogo :size="48" />
            </div>

            <div
                class="rounded-2xl border border-stone-200/90 bg-white px-6 py-7 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_12px_40px_-24px_rgba(15,23,42,0.18)]"
            >
                <h2 class="text-base font-semibold tracking-tight text-slate-900">
                    <template v-if="invitation">Join {{ invitation.team_name }}</template>
                    <template v-else>Sign in</template>
                </h2>

                <div
                    v-if="invitation"
                    class="mt-4 space-y-2 rounded-lg border border-brand-200 bg-brand-50 px-3 py-3 text-sm text-brand-900"
                >
                    <p>
                        An account already exists for
                        <strong>{{ invitation.email }}</strong>.
                        Enter that account’s password to join
                        <strong>{{ invitation.team_name }}</strong>.
                    </p>
                    <p class="text-brand-800/90">
                        Don’t know the password? Use
                        <strong>Forgot your password?</strong>
                        below — you’ll set a new one, then we’ll bring you into the business.
                    </p>
                </div>

                <div
                    v-else-if="status"
                    class="mt-4 rounded-lg bg-brand-50 px-3 py-2 text-sm font-medium text-brand-800"
                >
                    {{ status }}
                </div>

                <form class="mt-6 space-y-5" @submit.prevent="submit">
                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="mt-1 block w-full"
                            :class="{ 'bg-slate-50': invitation }"
                            required
                            :readonly="Boolean(invitation)"
                            :autofocus="!invitation"
                            autocomplete="username"
                        />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div>
                        <InputLabel for="password" value="Password" />
                        <TextInput
                            id="password"
                            v-model="form.password"
                            type="password"
                            class="mt-1 block w-full"
                            required
                            :autofocus="Boolean(invitation)"
                            autocomplete="current-password"
                        />
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>

                    <div class="flex items-center">
                        <label class="flex items-center">
                            <Checkbox v-model:checked="form.remember" name="remember" />
                            <span class="ms-2 text-sm text-slate-600">Remember me</span>
                        </label>
                    </div>

                    <div class="flex flex-col gap-3">
                        <PrimaryButton
                            class="w-full justify-center"
                            :class="{ 'opacity-25': form.processing }"
                            :disabled="form.processing"
                        >
                            {{ invitation ? 'Sign in & join' : 'Log in' }}
                        </PrimaryButton>
                        <Link
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="text-center text-sm font-medium text-brand-700 underline decoration-brand-300 underline-offset-2 hover:text-brand-800"
                        >
                            {{ invitation ? 'Forgot your password? Set a new one' : 'Forgot your password?' }}
                        </Link>
                    </div>
                </form>
            </div>
            <div class="mt-6">
                <AppVersionLabel align="center" />
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes login-fade-up {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.login-form-enter {
    animation: login-fade-up 0.5s ease-out both;
}

@media (prefers-reduced-motion: reduce) {
    .login-form-enter {
        animation: none;
    }
}
</style>
