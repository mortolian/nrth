import { config } from 'md-editor-v3';

let applied = false;

/**
 * Configure md-editor-v3 once so preview matches invoice/PDF rendering
 * (single newlines → <br>).
 */
export function ensureMarkdownEditorConfig(): void {
    if (applied) {
        return;
    }
    applied = true;

    config({
        markdownItConfig(md) {
            md.set({ breaks: true });
        },
    });
}
