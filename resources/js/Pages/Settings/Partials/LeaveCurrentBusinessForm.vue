<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';

const page = usePage();
const leaveModalOpen = ref(false);
const form = useForm({});

const currentTeam = computed(() => page.props.auth?.user?.current_team as { id: number; name: string } | undefined);
const authUserId = computed(() => page.props.auth?.user?.id as number | undefined);
const canLeave = computed(() => Boolean(page.props.can_leave_current_team));

const leave = () => {
    if (!currentTeam.value?.id || !authUserId.value) {
        return;
    }

    form.delete(route('team-members.destroy', [currentTeam.value.id, authUserId.value]), {
        errorBag: 'removeTeamMember',
        onSuccess: () => {
            leaveModalOpen.value = false;
        },
    });
};
</script>

<template>
    <section v-if="canLeave && currentTeam" class="rounded-xl border border-rose-200 bg-rose-50/40 p-4 md:p-5">
        <h4 class="text-sm font-semibold text-slate-900">Leave business</h4>
        <p class="mt-0.5 text-xs text-slate-500">
            You were invited to <span class="font-medium text-slate-700">{{ currentTeam.name }}</span>.
            Leaving removes your access immediately. You can be invited again later.
        </p>
        <div class="mt-4">
            <AppButton variant="secondary" class="!border-rose-300 !text-rose-700 hover:!bg-rose-50" @click="leaveModalOpen = true">
                Leave {{ currentTeam.name }}
            </AppButton>
            <p v-if="form.errors.team" class="mt-2 text-xs text-rose-600">{{ form.errors.team }}</p>
        </div>

        <div
            v-if="leaveModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
            @click.self="leaveModalOpen = false"
        >
            <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 shadow-lg">
                <h3 class="text-base font-semibold text-slate-900">Leave {{ currentTeam.name }}?</h3>
                <p class="mt-2 text-sm text-slate-600">
                    You will lose access to this business immediately. If you belong to another business, we will switch you there.
                </p>
                <div class="mt-5 flex justify-end gap-2">
                    <AppButton variant="ghost" @click="leaveModalOpen = false">Cancel</AppButton>
                    <AppButton variant="primary" class="!bg-rose-600" :disabled="form.processing" @click="leave">
                        Leave business
                    </AppButton>
                </div>
            </div>
        </div>
    </section>
</template>
