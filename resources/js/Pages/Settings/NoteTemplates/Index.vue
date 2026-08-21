<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import EmptyState from '@/Components/EmptyState.vue';
import FieldHelp from '@/Components/FieldHelp.vue';
import MarkdownEditor from '@/Components/MarkdownEditor.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import AppTabs from '@/Components/AppTabs.vue';
import { businessSettingsTabs } from '@/Composables/useBusinessSettingsTabs';
import { FileText, Pencil, Plus, Trash2 } from 'lucide-vue-next';

type Template = {
    id: number;
    name: string;
    body: string;
    target: 'notes' | 'footer';
    is_active: boolean;
    sort_order: number;
};

const props = defineProps<{ templates: Template[] }>();

const businessTabs = businessSettingsTabs({ linkAll: true });

const blankForm = () => ({
    name: '',
    body: '',
    is_active: true,
    sort_order: 0,
});

const form = ref(blankForm());
const editingId = ref<number | null>(null);
const formOpen = ref(false);
const saving = ref(false);

const isEditing = computed(() => editingId.value !== null);

/** Notes-only library (footer stays freeform per document). */
const noteTemplates = computed(() =>
    props.templates.filter((template) => template.target === 'notes'),
);

const startCreate = () => {
    editingId.value = null;
    form.value = blankForm();
    formOpen.value = true;
};

const startEdit = (template: Template) => {
    editingId.value = template.id;
    form.value = {
        name: template.name,
        body: template.body,
        is_active: template.is_active,
        sort_order: template.sort_order,
    };
    formOpen.value = true;
};

const cancelForm = () => {
    editingId.value = null;
    form.value = blankForm();
    formOpen.value = false;
};

const save = () => {
    if (saving.value) return;
    saving.value = true;

    const payload = {
        name: form.value.name,
        body: form.value.body,
        target: 'notes',
        is_active: form.value.is_active,
        sort_order: form.value.sort_order,
    };

    const opts = {
        onFinish: () => {
            saving.value = false;
        },
        onSuccess: () => {
            cancelForm();
        },
    };

    if (editingId.value !== null) {
        router.put(route('settings.note-templates.update', editingId.value), payload, opts);
        return;
    }

    router.post(route('settings.note-templates.store'), payload, opts);
};

const destroy = (id: number) => {
    if (!confirm('Delete this note template?')) return;
    router.delete(route('settings.note-templates.destroy', id), {
        onSuccess: () => {
            if (editingId.value === id) {
                cancelForm();
            }
        },
    });
};
</script>

<template>
    <SettingsShell
        section="business"
        title="Settings · Business"
        subtitle="Named markdown snippets (e.g. banking details) you can attach to clients and insert on invoices and estimates."
    >
        <div class="border-b border-slate-200">
            <AppTabs
                model-value="note_templates"
                :tabs="businessTabs"
                aria-label="Business settings"
            />
        </div>

        <div class="mt-6 space-y-6">
        <AppCard v-if="formOpen" class="space-y-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">
                    {{ isEditing ? 'Edit note template' : 'New note template' }}
                </h3>
                <p class="mt-1 text-xs text-slate-500">
                    Give it a clear name such as &ldquo;International Banking Details&rdquo;, then attach it on clients
                    or insert it while editing an invoice or estimate.
                </p>
            </div>

            <div>
                <FieldHelp label="Name" text="Shown when picking a template on clients and documents." />
                <AppInput v-model="form.name" placeholder="e.g. International Banking Details" />
            </div>

            <div>
                <FieldHelp
                    label="Body"
                    text="Markdown supported. This text is copied onto the document when the template is used."
                />
                <MarkdownEditor
                    v-model="form.body"
                    :rows="8"
                    placeholder="**Bank:** Example Bank&#10;Account name: Acme Ltd&#10;Account: `62012345678`&#10;Reference: invoice number"
                    aria-label="Note template body"
                />
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input v-model="form.is_active" type="checkbox" class="rounded border-slate-300" />
                Active (inactive templates stay saved but are hidden on clients and documents)
            </label>

            <FormActions>
                <AppButton variant="primary" :loading="saving" @click="save">
                    {{ isEditing ? 'Update template' : 'Save template' }}
                </AppButton>
                <AppButton variant="secondary" @click="cancelForm">Cancel</AppButton>
            </FormActions>
        </AppCard>

        <AppCard v-else class="overflow-hidden p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Your note templates</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ noteTemplates.length === 0 ? 'None yet' : `${noteTemplates.length} template${noteTemplates.length === 1 ? '' : 's'}` }}
                    </p>
                </div>
                <AppButton size="sm" variant="primary" type="button" @click="startCreate">
                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                    New template
                </AppButton>
            </div>

            <div v-if="noteTemplates.length === 0" class="p-4">
                <EmptyState
                    title="No note templates yet"
                    description="Create reusable notes such as banking details, then select them on clients or insert them on invoices and estimates."
                    :icon="FileText"
                >
                    <template #action>
                        <AppButton variant="primary" type="button" @click="startCreate">
                            <Plus class="mr-1.5 h-4 w-4" />
                            New template
                        </AppButton>
                    </template>
                </EmptyState>
            </div>

            <ul v-else class="divide-y divide-slate-100" role="list">
                <li
                    v-for="template in noteTemplates"
                    :key="template.id"
                    class="px-4 py-4 transition-colors hover:bg-slate-50/80"
                >
                    <div class="flex flex-col gap-3 sm:flex-row sm:gap-4">
                        <div class="flex min-w-0 flex-1 gap-3">
                            <div
                                class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500"
                            >
                                <FileText class="h-4 w-4" />
                            </div>

                            <div class="min-w-0 flex-1 self-center">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="truncate text-sm font-semibold text-slate-900">
                                        {{ template.name }}
                                    </h4>
                                    <AppBadge :variant="template.is_active ? 'success' : 'neutral'">
                                        {{ template.is_active ? 'Active' : 'Inactive' }}
                                    </AppBadge>
                                </div>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-2 sm:items-start">
                            <AppButton
                                size="sm"
                                variant="secondary"
                                type="button"
                                @click="startEdit(template)"
                            >
                                <Pencil class="mr-1.5 h-3.5 w-3.5" />
                                Edit
                            </AppButton>
                            <AppButton
                                size="sm"
                                variant="danger"
                                type="button"
                                @click="destroy(template.id)"
                            >
                                <Trash2 class="mr-1.5 h-3.5 w-3.5" />
                                Delete
                            </AppButton>
                        </div>
                    </div>
                </li>
            </ul>
        </AppCard>

        <p v-if="!formOpen" class="mt-4 text-xs text-slate-500">
            Tip: after saving templates, open a
            <Link :href="route('invoicing.clients.index')" class="font-medium text-brand-700 hover:underline">client</Link>
            and tick the ones that should prefill new invoices and estimates.
        </p>
        </div>
    </SettingsShell>
</template>
