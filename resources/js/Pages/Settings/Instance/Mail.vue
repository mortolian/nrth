<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import InstanceSettingsShell from '@/Components/InstanceSettingsShell.vue';
import { useToast } from '@/Composables/useToast';

type MailSettings = {
    enabled: boolean;
    host: string;
    port: number;
    scheme: string | null;
    username: string;
    password_set: boolean;
    from_address: string;
    from_name: string;
    using_instance: boolean;
    summary: string;
};

const props = defineProps<{
    mail: MailSettings;
}>();

const toast = useToast();

const mailForm = useForm({
    enabled: props.mail.enabled,
    host: props.mail.host,
    port: props.mail.port,
    scheme: props.mail.scheme ?? 'null',
    username: props.mail.username,
    password: '',
    from_address: props.mail.from_address,
    from_name: props.mail.from_name,
});

const testForm = useForm({
    host: '',
    port: 587 as number | undefined,
    scheme: 'tls' as string | undefined,
    username: '',
    password: '',
    from_address: '',
    from_name: '',
});

watch(
    () => props.mail,
    (next) => {
        mailForm.enabled = next.enabled;
        mailForm.host = next.host;
        mailForm.port = next.port;
        mailForm.scheme = next.scheme ?? 'null';
        mailForm.username = next.username;
        mailForm.password = '';
        mailForm.from_address = next.from_address;
        mailForm.from_name = next.from_name;
        mailForm.clearErrors();
        testForm.clearErrors();
    },
);

const fieldError = (key: keyof typeof mailForm.errors | string): string | undefined => {
    const fromMail = mailForm.errors[key as keyof typeof mailForm.errors];
    const fromTest = testForm.errors[key as keyof typeof testForm.errors];
    const value = fromMail || fromTest;
    if (Array.isArray(value)) {
        return value[0];
    }

    return value || undefined;
};

const testErrorMessage = computed(() => {
    const entries = Object.values(testForm.errors);
    for (const value of entries) {
        if (Array.isArray(value) && value[0]) {
            return String(value[0]);
        }
        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }
    }

    return null;
});

const smtpEnabled = computed(() => Boolean(mailForm.enabled));

const saveMail = () => {
    testForm.clearErrors();
    mailForm.put(route('settings.instance.mail.update'), {
        preserveScroll: true,
    });
};

const firstErrorMessage = (errors: Record<string, string | string[]>): string => {
    for (const value of Object.values(errors)) {
        if (Array.isArray(value) && value[0]) {
            return String(value[0]);
        }
        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }
    }

    return 'Could not send the test email. Check the SMTP settings and try again.';
};

const testMail = () => {
    if (!smtpEnabled.value) {
        toast.error('Enable instance SMTP before sending a test email.');
        return;
    }

    mailForm.clearErrors();
    testForm.clearErrors();
    testForm
        .transform(() => ({
            host: mailForm.host || undefined,
            port: mailForm.port || undefined,
            scheme: mailForm.scheme === 'null' ? 'null' : mailForm.scheme || undefined,
            username: mailForm.username || undefined,
            password: mailForm.password || undefined,
            from_address: mailForm.from_address || undefined,
            from_name: mailForm.from_name || undefined,
        }))
        .post(route('settings.instance.mail.test'), {
            preserveScroll: true,
            onError: (errors) => {
                toast.error(firstErrorMessage(errors as Record<string, string | string[]>));
            },
        });
};
</script>

<template>
    <InstanceSettingsShell section="mail">
        <AppCard>
            <p class="text-sm text-slate-600">
                When enabled, these settings override
                <code class="rounded bg-slate-100 px-1 text-xs">MAIL_*</code>
                from
                <code class="rounded bg-slate-100 px-1 text-xs">.env</code>
                for the whole install. Leave the password blank when saving to keep the stored value.
                Business email templates stay under Settings → Business.
            </p>
            <p class="mt-2 text-xs text-slate-500">
                Status: {{ mail.summary }}
            </p>

            <form class="mt-4 space-y-6" @submit.prevent="saveMail">
                <div>
                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-800">
                        <input
                            id="instance-smtp-enabled"
                            v-model="mailForm.enabled"
                            type="checkbox"
                            class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        />
                        Use instance SMTP (override .env)
                    </label>
                    <p class="mt-1 text-xs text-slate-500">
                        When off, the install keeps using
                        <code class="rounded bg-slate-100 px-1">MAIL_*</code>
                        from
                        <code class="rounded bg-slate-100 px-1">.env</code>.
                        Turn this on to edit and use the settings below.
                    </p>
                    <p v-if="fieldError('enabled')" class="mt-1 text-xs text-rose-600">{{ fieldError('enabled') }}</p>
                </div>

                <div
                    class="space-y-6 transition-opacity"
                    :class="{ 'pointer-events-none opacity-45': !smtpEnabled }"
                    :aria-disabled="!smtpEnabled"
                >
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="mb-1.5 block text-xs font-medium text-slate-500" for="instance-smtp-host">Host</label>
                            <AppInput
                                id="instance-smtp-host"
                                v-model="mailForm.host"
                                type="text"
                                autocomplete="off"
                                placeholder="smtp.example.com"
                                :disabled="!smtpEnabled"
                            />
                            <p v-if="fieldError('host')" class="mt-1 text-xs text-rose-600">{{ fieldError('host') }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500" for="instance-smtp-port">Port</label>
                            <AppInput
                                id="instance-smtp-port"
                                v-model="mailForm.port"
                                type="number"
                                min="1"
                                max="65535"
                                :disabled="!smtpEnabled"
                            />
                            <p v-if="fieldError('port')" class="mt-1 text-xs text-rose-600">{{ fieldError('port') }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500" for="instance-smtp-scheme">Encryption</label>
                            <select
                                id="instance-smtp-scheme"
                                v-model="mailForm.scheme"
                                class="block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 disabled:cursor-not-allowed disabled:bg-slate-100"
                                :disabled="!smtpEnabled"
                            >
                                <option value="tls">TLS (STARTTLS)</option>
                                <option value="smtps">SMTPS (implicit TLS)</option>
                                <option value="null">None</option>
                            </select>
                            <p v-if="fieldError('scheme')" class="mt-1 text-xs text-rose-600">{{ fieldError('scheme') }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500" for="instance-smtp-username">Username</label>
                            <AppInput
                                id="instance-smtp-username"
                                v-model="mailForm.username"
                                type="text"
                                autocomplete="off"
                                :disabled="!smtpEnabled"
                            />
                            <p v-if="fieldError('username')" class="mt-1 text-xs text-rose-600">{{ fieldError('username') }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500" for="instance-smtp-password">Password</label>
                            <AppInput
                                id="instance-smtp-password"
                                v-model="mailForm.password"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="mail.password_set ? '•••••••• (unchanged)' : ''"
                                :disabled="!smtpEnabled"
                            />
                            <p v-if="fieldError('password')" class="mt-1 text-xs text-rose-600">{{ fieldError('password') }}</p>
                            <p v-else-if="mail.password_set" class="mt-1 text-xs text-slate-500">Password is saved.</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500" for="instance-smtp-from-address">From address</label>
                            <AppInput
                                id="instance-smtp-from-address"
                                v-model="mailForm.from_address"
                                type="email"
                                autocomplete="off"
                                placeholder="noreply@example.com"
                                :disabled="!smtpEnabled"
                            />
                            <p v-if="fieldError('from_address')" class="mt-1 text-xs text-rose-600">{{ fieldError('from_address') }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-slate-500" for="instance-smtp-from-name">From name</label>
                            <AppInput
                                id="instance-smtp-from-name"
                                v-model="mailForm.from_name"
                                type="text"
                                autocomplete="off"
                                :disabled="!smtpEnabled"
                            />
                            <p v-if="fieldError('from_name')" class="mt-1 text-xs text-rose-600">{{ fieldError('from_name') }}</p>
                        </div>
                    </div>

                    <div
                        v-if="testErrorMessage"
                        class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                        role="alert"
                    >
                        {{ testErrorMessage }}
                    </div>
                </div>

                <FormActions class="!mt-2">
                    <AppButton type="submit" variant="primary" :loading="mailForm.processing">
                        {{ mailForm.processing ? 'Saving…' : 'Save email settings' }}
                    </AppButton>
                    <AppButton
                        type="button"
                        variant="secondary"
                        :loading="testForm.processing"
                        :disabled="!smtpEnabled || testForm.processing"
                        @click="testMail"
                    >
                        {{ testForm.processing ? 'Sending…' : 'Send test email to me' }}
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </InstanceSettingsShell>
</template>
