<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    invitation: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: '',
    email: props.invitation.email,
    password: '',
    password_confirmation: '',
    terms: false,
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head :title="`Join ${invitation.team_name}`" />

    <div
        class="relative flex min-h-screen flex-col justify-center overflow-hidden px-6 py-12"
        style="background: linear-gradient(180deg, #d9d5cc 0%, #ebe8e1 40%, #f3f1ec 70%, #f7f6f3 100%);"
    >
        <div
            class="pointer-events-none absolute inset-0 opacity-[0.45]"
            aria-hidden="true"
            style="background-image: radial-gradient(circle at 1px 1px, rgba(120, 110, 95, 0.18) 1px, transparent 0); background-size: 20px 20px;"
        />

        <div class="relative z-10 mx-auto w-full max-w-[24rem]">
            <div class="mb-8 flex flex-col items-center text-center">
                <AuthenticationCardLogo :size="48" />
            </div>

            <div
                class="rounded-2xl border border-stone-200/90 bg-white px-6 py-7 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_12px_40px_-24px_rgba(15,23,42,0.18)]"
            >
                <p class="text-xs font-medium uppercase tracking-wide text-brand-700">
                    Invitation
                </p>
                <h2 class="mt-1 text-lg font-semibold tracking-tight text-slate-900">
                    Join {{ invitation.team_name }}
                </h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    Create your account to join as
                    <span class="font-medium text-slate-800">{{ invitation.role_label }}</span>.
                    You won’t need to set up a new business.
                </p>

                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <div>
                        <InputLabel for="name" value="Your name" />
                        <TextInput
                            id="name"
                            v-model="form.name"
                            type="text"
                            class="mt-1 block w-full"
                            required
                            autofocus
                            autocomplete="name"
                        />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput
                            id="email"
                            v-model="form.email"
                            type="email"
                            class="mt-1 block w-full bg-slate-50"
                            required
                            readonly
                            autocomplete="username"
                        />
                        <p class="mt-1 text-xs text-slate-500">
                            This invitation is locked to this email address.
                        </p>
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
                            autocomplete="new-password"
                        />
                        <InputError class="mt-2" :message="form.errors.password" />
                    </div>

                    <div>
                        <InputLabel for="password_confirmation" value="Confirm password" />
                        <TextInput
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            class="mt-1 block w-full"
                            required
                            autocomplete="new-password"
                        />
                        <InputError class="mt-2" :message="form.errors.password_confirmation" />
                    </div>

                    <div v-if="$page.props.jetstream.hasTermsAndPrivacyPolicyFeature">
                        <InputLabel for="terms">
                            <div class="flex items-start gap-2">
                                <Checkbox id="terms" v-model:checked="form.terms" name="terms" required class="mt-0.5" />
                                <span class="text-sm text-slate-600">
                                    I agree to the
                                    <a
                                        target="_blank"
                                        :href="route('terms.show')"
                                        class="underline"
                                    >Terms of Service</a>
                                    and
                                    <a
                                        target="_blank"
                                        :href="route('policy.show')"
                                        class="underline"
                                    >Privacy Policy</a>
                                </span>
                            </div>
                            <InputError class="mt-2" :message="form.errors.terms" />
                        </InputLabel>
                    </div>

                    <PrimaryButton
                        class="mt-2 w-full justify-center"
                        :class="{ 'opacity-25': form.processing }"
                        :disabled="form.processing"
                    >
                        Create account &amp; join
                    </PrimaryButton>
                </form>
            </div>
        </div>
    </div>
</template>
