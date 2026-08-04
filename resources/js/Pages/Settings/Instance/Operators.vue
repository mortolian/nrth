<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InstanceSettingsShell from '@/Components/InstanceSettingsShell.vue';

type OperatorRow = {
    id: number | null;
    name: string | null;
    email: string;
    source: string;
    can_remove: boolean;
};

defineProps<{
    operators: OperatorRow[];
    env_break_glass_configured: boolean;
}>();

const operatorForm = useForm({
    email: '',
});

const addOperator = () => {
    operatorForm.post(route('settings.instance.operators.store'), {
        preserveScroll: true,
        onSuccess: () => operatorForm.reset('email'),
    });
};

const removeOperator = (row: OperatorRow) => {
    if (!row.id || !row.can_remove) {
        return;
    }
    if (!confirm(`Remove ${row.email} as an instance operator?`)) {
        return;
    }
    useForm({}).delete(route('settings.instance.operators.destroy', row.id), {
        preserveScroll: true,
    });
};

const operatorSourceLabel = (source: string) => {
    if (source === 'environment') {
        return 'Environment';
    }
    if (source === 'database+environment') {
        return 'Database + environment';
    }

    return 'Database';
};
</script>

<template>
    <InstanceSettingsShell section="operators">
        <AppCard>
            <p class="text-sm text-slate-600">
                Separate from business ownership.
                Operators manage instance settings (email, operators) and whole-server backups.
                The first registered user is promoted automatically; add others only when you need them.
            </p>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-slate-600">
                            <th class="px-2 py-2 font-medium">Name</th>
                            <th class="px-2 py-2 font-medium">Email</th>
                            <th class="px-2 py-2 font-medium">Source</th>
                            <th class="px-2 py-2 font-medium" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in operators" :key="`${row.source}-${row.email}`" class="border-b border-slate-100">
                            <td class="px-2 py-2">{{ row.name || '—' }}</td>
                            <td class="px-2 py-2">{{ row.email }}</td>
                            <td class="px-2 py-2">{{ operatorSourceLabel(row.source) }}</td>
                            <td class="px-2 py-2 text-right">
                                <button
                                    v-if="row.can_remove && row.id"
                                    type="button"
                                    class="text-rose-600 hover:underline"
                                    @click="removeOperator(row)"
                                >
                                    Remove
                                </button>
                                <span v-else-if="row.source === 'environment'" class="text-xs text-slate-500">
                                    Edit NRTH_OPERATOR_EMAILS in .env
                                </span>
                                <span v-else class="text-xs text-slate-500">Last operator</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form class="mt-5 grid gap-3 md:grid-cols-[1fr_auto] md:items-end" @submit.prevent="addOperator">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Add operator by email</label>
                    <AppInput v-model="operatorForm.email" type="email" required placeholder="user@example.com" />
                    <p v-if="operatorForm.errors.email" class="mt-1 text-xs text-rose-600">{{ operatorForm.errors.email }}</p>
                </div>
                <AppButton type="submit" variant="primary" :loading="operatorForm.processing">
                    {{ operatorForm.processing ? 'Adding…' : 'Add operator' }}
                </AppButton>
            </form>

            <p v-if="env_break_glass_configured" class="mt-3 text-xs text-slate-500">
                Break-glass emails from NRTH_OPERATOR_EMAILS are active. You can clear that env var once database operators are set.
            </p>
        </AppCard>
    </InstanceSettingsShell>
</template>
