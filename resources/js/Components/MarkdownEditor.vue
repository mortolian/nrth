<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { MdEditor, type ToolbarNames } from 'md-editor-v3';
import 'md-editor-v3/lib/style.css';
import { ensureMarkdownEditorConfig } from '@/Support/markdownEditorConfig';

ensureMarkdownEditorConfig();

const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        placeholder?: string;
        rows?: number;
        disabled?: boolean;
        /** Accessible name when no visible label wraps the control. */
        ariaLabel?: string;
    }>(),
    {
        modelValue: '',
        placeholder: 'Write markdown…',
        rows: 6,
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

/** Stable unique id for md-editor-v3 (required when multiple editors are on one page). */
const editorId = `md-editor-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;

/** Local mirror so programmatic parent updates (e.g. insert template) reach the editor. */
const localValue = ref(props.modelValue ?? '');

watch(
    () => props.modelValue,
    (next) => {
        const normalized = next ?? '';
        if (normalized !== localValue.value) {
            localValue.value = normalized;
        }
    },
);

watch(localValue, (next) => {
    if (next !== (props.modelValue ?? '')) {
        emit('update:modelValue', next);
    }
});

const editorHeight = computed(() => `${Math.max(props.rows, 4) * 1.55 + 5.5}rem`);

/**
 * Keep the toolbar focused on invoice/estimate copy.
 * Heavier features stay available via package updates without us maintaining them.
 */
const toolbarsExclude: ToolbarNames[] = [
    'github',
    'mermaid',
    'katex',
    'save',
    'htmlPreview',
    'catalog',
    'image',
    'prettier',
];
</script>

<template>
    <div class="nrth-markdown-editor overflow-hidden rounded-md border border-slate-300" :aria-label="ariaLabel">
        <MdEditor
            :id="editorId"
            v-model="localValue"
            language="en-US"
            theme="light"
            preview-theme="github"
            :placeholder="placeholder"
            :disabled="disabled"
            :style="{ height: editorHeight }"
            :toolbars-exclude="toolbarsExclude"
            :footers="[]"
            no-upload-img
            no-mermaid
            no-katex
            :show-code-row-number="false"
        />
    </div>
</template>

<style scoped>
.nrth-markdown-editor :deep(.md-editor) {
    border: 0;
    border-radius: 0;
}
</style>
