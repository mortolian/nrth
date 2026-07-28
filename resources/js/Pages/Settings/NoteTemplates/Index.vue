<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';
import { useToast } from '@/Composables/useToast';

type Template = {
    id: number;
    name: string;
    body: string;
    target: 'notes' | 'footer';
    is_active: boolean;
    sort_order: number;
};

const props = defineProps<{ templates: Template[] }>();
const toast = useToast();

const form = ref({
    name: '',
    body: '',
    target: 'notes' as 'notes' | 'footer',
    is_active: true,
    sort_order: 0,
});

const save = () => {
    router.post(route('settings.note-templates.store'), form.value, {
        onSuccess: () => {
            toast.success('Note template created.');
            form.value = { name: '', body: '', target: 'notes', is_active: true, sort_order: 0 };
        },
    });
};

const destroy = (id: number) => {
    if (!confirm('Delete this template?')) return;
    router.delete(route('settings.note-templates.destroy', id), {
        onSuccess: () => toast.success('Note template deleted.'),
    });
};
</script>

<template>
    <SettingsShell section="note-templates" title="Note templates" subtitle="Reusable markdown snippets for invoice notes and footers">
        <AppCard class="mt-5 space-y-3">
            <h3 class="text-sm font-semibold text-slate-900">New template</h3>
            <AppInput v-model="form.name" placeholder="Name (e.g. Banking details)" />
            <textarea v-model="form.body" rows="4" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" placeholder="Markdown body…" />
            <div class="grid gap-3 sm:grid-cols-2">
                <AppSelect
                    :model-value="form.target"
                    :options="[
                        { label: 'Notes', value: 'notes' },
                        { label: 'Footer / terms', value: 'footer' },
                    ]"
                    @update:model-value="form.target = $event as 'notes' | 'footer'"
                />
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                    Active
                </label>
            </div>
            <FormActions>
                <AppButton variant="primary" @click="save">Save template</AppButton>
            </FormActions>
        </AppCard>

        <AppCard class="mt-5 overflow-hidden p-0">
            <AppTable>
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-500">
                        <th class="px-3 py-2">Name</th>
                        <th class="px-3 py-2">Target</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="templates.length === 0">
                        <td colspan="4" class="px-3 py-6 text-sm text-slate-500">No templates yet.</td>
                    </tr>
                    <tr v-for="template in templates" :key="template.id" class="border-b border-slate-100">
                        <td class="px-3 py-2">
                            <div class="font-medium text-slate-900">{{ template.name }}</div>
                            <div class="truncate text-xs text-slate-500">{{ template.body }}</div>
                        </td>
                        <td class="px-3 py-2 capitalize">{{ template.target }}</td>
                        <td class="px-3 py-2">{{ template.is_active ? 'Active' : 'Inactive' }}</td>
                        <td class="px-3 py-2 text-right">
                            <AppButton size="sm" variant="danger" @click="destroy(template.id)">Delete</AppButton>
                        </td>
                    </tr>
                </tbody>
            </AppTable>
        </AppCard>
    </SettingsShell>
</template>
