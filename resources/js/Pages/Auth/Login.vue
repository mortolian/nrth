<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
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
        class="relative flex min-h-screen flex-col justify-center px-6 py-12"
        style="background: linear-gradient(180deg, #f3f1ec 0%, #faf9f7 42%, #f0eeea 100%);"
    >
        <div class="login-form-enter mx-auto w-full max-w-[22rem]">
            <div class="mb-10 flex flex-col items-center text-center">
                <AuthenticationCardLogo :size="48" />
            </div>

            <div
                class="rounded-2xl border border-stone-200/90 bg-white px-6 py-7 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_12px_40px_-24px_rgba(15,23,42,0.18)]"
            >
                <h2 class="text-base font-semibold tracking-tight text-slate-900">
                    Sign in
                </h2>

                <div
                    v-if="status"
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
                            required
                            autofocus
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
                            Log in
                        </PrimaryButton>
                        <Link
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="text-center text-sm text-slate-500 underline decoration-slate-300 underline-offset-2 hover:text-slate-800"
                        >
                            Forgot your password?
                        </Link>
                    </div>
                </form>
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
