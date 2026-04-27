<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import { useAppDisplayName } from '@/lib/appName';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const appDisplayName = useAppDisplayName();

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

    <div class="flex min-h-screen flex-col bg-white lg:flex-row">
        <!-- Form panel (left on lg+, top on mobile) -->
        <div
            class="flex flex-1 flex-col justify-center px-6 py-10 sm:px-10 lg:w-1/2 lg:px-16 lg:py-12"
        >
            <div class="mx-auto w-full max-w-md">

                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">
                    Sign in
                </h1>

                <div v-if="status" class="mt-6 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800">
                    {{ status }}
                </div>

                <form class="mt-8 space-y-5" @submit.prevent="submit">
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

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <Link
                            v-if="canResetPassword"
                            :href="route('password.request')"
                            class="order-2 text-center text-sm text-slate-600 underline decoration-slate-300 underline-offset-2 hover:text-slate-900 sm:order-1 sm:me-auto sm:text-left"
                        >
                            Forgot your password?
                        </Link>
                        <PrimaryButton
                            class="order-1 sm:order-2"
                            :class="{ 'opacity-25': form.processing }"
                            :disabled="form.processing"
                        >
                            Log in
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>

        <!-- Visual panel (right on lg+, bottom band on mobile) -->
        <div class="relative h-44 shrink-0 overflow-hidden lg:h-auto lg:min-h-screen lg:w-1/2 hidden lg:block">
            <img
                src="/images/login-side.jpg"
                alt=""
                class="absolute inset-0 h-full w-full object-cover"
                width="900"
                height="1200"
            >

            <div
                class="relative flex h-full flex-col items-center justify-center px-8 py-6 text-center lg:p-12"
            >
                <AuthenticationCardLogo class="opacity-70 drop-shadow-sm" />
            </div>
        </div>
    </div>
</template>
