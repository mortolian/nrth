<script setup>
import { useForm, usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import { useToast } from '@/Composables/useToast';

const toast = useToast();
const page = usePage();
const authUser = computed(() => page.props.auth?.user);

const form = useForm({
    name: '',
});

const createTeam = () => {
    form.post(route('teams.store'), {
        errorBag: 'createTeam',
        preserveScroll: true,
        onError: () => {
            if (!form.hasErrors) {
                toast.error('Could not create the business.');
            }
        },
    });
};
</script>

<template>
    <AppCard>
        <form class="max-w-xl space-y-5" @submit.prevent="createTeam">
            <div>
                <label for="business-name" class="mb-1.5 block text-xs font-medium text-slate-500">Business name</label>
                <AppInput
                    id="business-name"
                    v-model="form.name"
                    type="text"
                    autofocus
                    required
                    placeholder="e.g. Acme Consulting"
                />
                <p v-if="form.errors.name" class="mt-1.5 text-xs text-rose-600">{{ form.errors.name }}</p>
            </div>

            <div>
                <p class="mb-1.5 block text-xs font-medium text-slate-500">Owner</p>
                <div class="flex items-center gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5">
                    <img
                        v-if="authUser?.profile_photo_url"
                        class="h-10 w-10 rounded-full object-cover"
                        :src="authUser.profile_photo_url"
                        :alt="authUser?.name"
                    >
                    <span
                        v-else
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-800"
                    >
                        {{ (authUser?.name || 'U').slice(0, 1).toUpperCase() }}
                    </span>
                    <div class="min-w-0 leading-tight">
                        <p class="truncate text-sm font-medium text-slate-900">{{ authUser?.name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ authUser?.email }}</p>
                    </div>
                </div>
            </div>

            <FormActions class="!mt-2">
                <AppButton type="submit" variant="primary" :loading="form.processing">
                    {{ form.processing ? 'Creating…' : 'Create business' }}
                </AppButton>
                <AppButton
                    type="button"
                    variant="secondary"
                    :disabled="form.processing"
                    @click="router.visit(route('dashboard'))"
                >
                    Cancel
                </AppButton>
            </FormActions>
        </form>
    </AppCard>
</template>
