<script setup>
import { useForm } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useToast } from '@/Composables/useToast';

const toast = useToast();

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
    <FormSection @submitted="createTeam">
        <template #title>
            Business details
        </template>

        <template #description>
            Create another business with its own books, clients, and settings.
        </template>

        <template #form>
            <div class="col-span-6">
                <InputLabel value="Owner" />

                <div class="flex items-center mt-2">
                    <img class="object-cover size-12 rounded-full" :src="$page.props.auth.user.profile_photo_url" :alt="$page.props.auth.user.name">

                    <div class="ms-4 leading-tight">
                        <div class="text-gray-900">{{ $page.props.auth.user.name }}</div>
                        <div class="text-sm text-gray-700">
                            {{ $page.props.auth.user.email }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-span-6 sm:col-span-4">
                <InputLabel for="name" value="Business name" />
                <TextInput
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="block w-full mt-1"
                    autofocus
                />
                <InputError :message="form.errors.name" class="mt-2" />
            </div>
        </template>

        <template #actions>
            <AppButton type="submit" variant="primary" :loading="form.processing">
                {{ form.processing ? 'Creating…' : 'Create' }}
            </AppButton>
        </template>
    </FormSection>
</template>
