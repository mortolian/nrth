<script setup lang="ts">
import { computed, watch } from 'vue';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import Link from '@tiptap/extension-link';
import { Markdown } from '@tiptap/markdown';
import {
    Bold,
    Heading2,
    Heading3,
    Italic,
    Link2,
    List,
    ListOrdered,
    Redo2,
    Undo2,
} from 'lucide-vue-next';

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
        placeholder: 'Write notes…',
        rows: 6,
        disabled: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const editorMinHeight = computed(() => `${Math.max(props.rows, 4) * 1.55}rem`);

const editor = useEditor({
    extensions: [
        StarterKit.configure({
            heading: { levels: [2, 3] },
            code: false,
            codeBlock: false,
            horizontalRule: false,
            // Keep bullet/ordered lists, bold, italic, strike, blockquote, hardBreak.
        }),
        Placeholder.configure({
            placeholder: () => props.placeholder,
        }),
        Link.configure({
            openOnClick: false,
            autolink: true,
            defaultProtocol: 'https',
            HTMLAttributes: {
                rel: 'noopener noreferrer nofollow',
                class: 'text-brand-700 underline underline-offset-2',
            },
        }),
        Markdown.configure({
            // Match InvoiceMarkdownRenderer: single newlines → line breaks.
            markedOptions: {
                gfm: true,
                breaks: true,
            },
        }),
    ],
    content: props.modelValue ?? '',
    contentType: 'markdown',
    editable: !props.disabled,
    editorProps: {
        attributes: {
            class: 'nrth-tiptap-prose focus:outline-none',
            ...(props.ariaLabel ? { 'aria-label': props.ariaLabel } : {}),
        },
    },
    onUpdate: ({ editor: instance }) => {
        emit('update:modelValue', instance.getMarkdown());
    },
});

watch(
    () => props.modelValue,
    (next) => {
        if (!editor.value) {
            return;
        }
        const normalized = next ?? '';
        if (normalized === editor.value.getMarkdown()) {
            return;
        }
        editor.value.commands.setContent(normalized, { contentType: 'markdown' });
    },
);

watch(
    () => props.disabled,
    (disabled) => {
        editor.value?.setEditable(!disabled);
    },
);

const setLink = () => {
    if (!editor.value || props.disabled) {
        return;
    }

    const previous = editor.value.getAttributes('link').href as string | undefined;
    const url = window.prompt('Link URL', previous ?? 'https://');

    if (url === null) {
        return;
    }

    const trimmed = url.trim();
    if (trimmed === '') {
        editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
        return;
    }

    editor.value.chain().focus().extendMarkRange('link').setLink({ href: trimmed }).run();
};
</script>

<template>
    <div
        class="nrth-markdown-editor overflow-hidden rounded-md border border-slate-300 bg-white focus-within:border-brand-500 focus-within:ring-1 focus-within:ring-brand-500"
        :aria-disabled="disabled || undefined"
    >
        <div
            v-if="editor"
            class="flex flex-wrap items-center gap-0.5 border-b border-slate-200 bg-slate-50 px-1.5 py-1"
            role="toolbar"
            aria-label="Formatting"
        >
            <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-200/80 disabled:opacity-40"
                :class="{ 'bg-slate-200 text-slate-900': editor.isActive('bold') }"
                :disabled="disabled"
                title="Bold"
                aria-label="Bold"
                @click="editor.chain().focus().toggleBold().run()"
            >
                <Bold class="h-4 w-4" />
            </button>
            <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-200/80 disabled:opacity-40"
                :class="{ 'bg-slate-200 text-slate-900': editor.isActive('italic') }"
                :disabled="disabled"
                title="Italic"
                aria-label="Italic"
                @click="editor.chain().focus().toggleItalic().run()"
            >
                <Italic class="h-4 w-4" />
            </button>
            <span class="mx-1 h-5 w-px bg-slate-200" aria-hidden="true" />
            <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-200/80 disabled:opacity-40"
                :class="{ 'bg-slate-200 text-slate-900': editor.isActive('heading', { level: 2 }) }"
                :disabled="disabled"
                title="Heading"
                aria-label="Heading"
                @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
            >
                <Heading2 class="h-4 w-4" />
            </button>
            <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-200/80 disabled:opacity-40"
                :class="{ 'bg-slate-200 text-slate-900': editor.isActive('heading', { level: 3 }) }"
                :disabled="disabled"
                title="Subheading"
                aria-label="Subheading"
                @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"
            >
                <Heading3 class="h-4 w-4" />
            </button>
            <span class="mx-1 h-5 w-px bg-slate-200" aria-hidden="true" />
            <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-200/80 disabled:opacity-40"
                :class="{ 'bg-slate-200 text-slate-900': editor.isActive('bulletList') }"
                :disabled="disabled"
                title="Bullet list"
                aria-label="Bullet list"
                @click="editor.chain().focus().toggleBulletList().run()"
            >
                <List class="h-4 w-4" />
            </button>
            <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-200/80 disabled:opacity-40"
                :class="{ 'bg-slate-200 text-slate-900': editor.isActive('orderedList') }"
                :disabled="disabled"
                title="Numbered list"
                aria-label="Numbered list"
                @click="editor.chain().focus().toggleOrderedList().run()"
            >
                <ListOrdered class="h-4 w-4" />
            </button>
            <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-200/80 disabled:opacity-40"
                :class="{ 'bg-slate-200 text-slate-900': editor.isActive('link') }"
                :disabled="disabled"
                title="Link"
                aria-label="Link"
                @click="setLink"
            >
                <Link2 class="h-4 w-4" />
            </button>
            <span class="mx-1 h-5 w-px bg-slate-200" aria-hidden="true" />
            <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-200/80 disabled:opacity-40"
                :disabled="disabled || !editor.can().undo()"
                title="Undo"
                aria-label="Undo"
                @click="editor.chain().focus().undo().run()"
            >
                <Undo2 class="h-4 w-4" />
            </button>
            <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded text-slate-600 hover:bg-slate-200/80 disabled:opacity-40"
                :disabled="disabled || !editor.can().redo()"
                title="Redo"
                aria-label="Redo"
                @click="editor.chain().focus().redo().run()"
            >
                <Redo2 class="h-4 w-4" />
            </button>
        </div>

        <EditorContent
            :editor="editor"
            class="nrth-tiptap-surface px-3 py-2 text-sm text-slate-800"
            :style="{ minHeight: editorMinHeight }"
        />
    </div>
</template>

<style scoped>
.nrth-tiptap-surface :deep(.tiptap) {
    min-height: inherit;
}

.nrth-tiptap-surface :deep(.tiptap p.is-editor-empty:first-child::before) {
    color: #94a3b8;
    content: attr(data-placeholder);
    float: left;
    height: 0;
    pointer-events: none;
}

.nrth-tiptap-surface :deep(.nrth-tiptap-prose) {
    line-height: 1.55;
}

.nrth-tiptap-surface :deep(.nrth-tiptap-prose > * + *) {
    margin-top: 0.65em;
}

.nrth-tiptap-surface :deep(.nrth-tiptap-prose h2) {
    font-size: 1.05rem;
    font-weight: 650;
    line-height: 1.3;
}

.nrth-tiptap-surface :deep(.nrth-tiptap-prose h3) {
    font-size: 0.95rem;
    font-weight: 600;
    line-height: 1.35;
}

.nrth-tiptap-surface :deep(.nrth-tiptap-prose ul) {
    list-style: disc;
    padding-left: 1.25rem;
}

.nrth-tiptap-surface :deep(.nrth-tiptap-prose ol) {
    list-style: decimal;
    padding-left: 1.25rem;
}

.nrth-tiptap-surface :deep(.nrth-tiptap-prose blockquote) {
    border-left: 3px solid #cbd5e1;
    color: #475569;
    padding-left: 0.75rem;
}

.nrth-tiptap-surface :deep(.nrth-tiptap-prose strong) {
    font-weight: 650;
}

.nrth-tiptap-surface :deep(.nrth-tiptap-prose em) {
    font-style: italic;
}

.nrth-tiptap-surface :deep(.nrth-tiptap-prose s) {
    text-decoration: line-through;
}
</style>
