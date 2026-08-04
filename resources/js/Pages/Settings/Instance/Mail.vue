<script setup lang="ts">
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Mail } from 'lucide-vue-next';
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
    test_to_default: string;
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
    to: props.test_to_default,
    host: '',
    port: 587 as number | undefined,
    scheme: 'smtp' as string | undefined,
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

const canSendTest = computed(
    () => smtpEnabled.value && !testForm.processing && !mailForm.processing,
);

const useMyEmail = () => {
    testForm.to = props.test_to_default;
    testForm.clearErrors('to');
};

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
            to: testForm.to || undefined,
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
        <div class="space-y-5">
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

                <form class="mt-5 space-y-6" @submit.prevent="saveMail">
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
                        class="grid gap-3 transition-opacity sm:grid-cols-2"
                        :class="{ 'pointer-events-none opacity-45': !smtpEnabled }"
                        :aria-disabled="!smtpEnabled"
                    >
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
                            <p v-if="fieldError('host') && !testErrorMessage" class="mt-1 text-xs text-rose-600">{{ fieldError('host') }}</p>
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
                                <option value="smtp">TLS (STARTTLS)</option>
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

                    <FormActions bordered>
                        <AppButton type="submit" variant="primary" :loading="mailForm.processing" :disabled="testForm.processing">
                            {{ mailForm.processing ? 'Saving…' : 'Save email settings' }}
                        </AppButton>
                    </FormActions>
                </form>
            </AppCard>

            <AppCard>
                <div
                    class="transition-opacity"
                    :class="{ 'pointer-events-none opacity-45': !smtpEnabled }"
                    :aria-disabled="!smtpEnabled"
                >
                    <div class="flex items-start gap-3">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-600">
                            <Mail class="h-4 w-4" aria-hidden="true" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-semibold text-slate-900">Test delivery</h3>
                            <p class="mt-0.5 text-sm text-slate-600">
                                Send a one-off message with the settings above (including unsaved changes).
                                Save first if you want those settings kept for the install.
                            </p>
                        </div>
                    </div>

                    <p v-if="!smtpEnabled" class="mt-4 text-sm text-slate-500">
                        Enable instance SMTP above to send a test email.
                    </p>

                    <div v-else class="mt-4 space-y-3">
                        <div
                            v-if="testErrorMessage"
                            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                            role="alert"
                        >
                            {{ testErrorMessage }}
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div class="min-w-0 flex-1">
                                <label class="mb-1.5 block text-xs font-medium text-slate-500" for="instance-smtp-test-to">
                                    Recipient
                                </label>
                                <AppInput
                                    id="instance-smtp-test-to"
                                    v-model="testForm.to"
                                    type="email"
                                    autocomplete="email"
                                    :placeholder="test_to_default"
                                    :disabled="!canSendTest"
                                />
                                <p v-if="fieldError('to')" class="mt-1 text-xs text-rose-600">{{ fieldError('to') }}</p>
                            </div>
                            <AppButton
                                type="button"
                                variant="secondary"
                                class="shrink-0"
                                :disabled="!canSendTest || testForm.to === test_to_default"
                                @click="useMyEmail"
                            >
                                Use my email
                            </AppButton>
                            <AppButton
                                type="button"
                                variant="secondary"
                                class="shrink-0"
                                :loading="testForm.processing"
                                :disabled="!canSendTest"
                                @click="testMail"
                            >
                                {{ testForm.processing ? 'Sending…' : 'Send test email' }}
                            </AppButton>
                        </div>
                    </div>
                </div>
            </AppCard>
        </div>
    </InstanceSettingsShell>
</template>
