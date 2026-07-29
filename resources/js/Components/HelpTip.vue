<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { CircleHelp } from 'lucide-vue-next';

defineProps<{
    text: string;
    /** Accessible name for the trigger (defaults to the tip text). */
    label?: string;
}>();

const open = ref(false);
const buttonRef = ref<HTMLButtonElement | null>(null);
const tipStyle = ref<Record<string, string>>({});

const updatePosition = () => {
    const el = buttonRef.value;
    if (!el) {
        return;
    }

    const rect = el.getBoundingClientRect();
    const tipWidth = 288; // w-72
    const left = Math.min(rect.left, window.innerWidth - tipWidth - 8);

    tipStyle.value = {
        position: 'fixed',
        top: `${rect.bottom + 8}px`,
        left: `${Math.max(8, left)}px`,
        zIndex: '60',
    };
};

const show = async () => {
    open.value = true;
    await nextTick();
    updatePosition();
};

const hide = () => {
    open.value = false;
};

onBeforeUnmount(hide);
</script>

<template>
    <span class="inline-flex" @click.stop>
        <button
            ref="buttonRef"
            type="button"
            class="inline-flex rounded text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
            :aria-label="label ?? text"
            @mouseenter="show"
            @mouseleave="hide"
            @focus="show"
            @blur="hide"
        >
            <CircleHelp class="h-3.5 w-3.5" aria-hidden="true" />
        </button>
        <Teleport to="body">
            <span
                v-if="open"
                role="tooltip"
                class="pointer-events-none w-72 rounded-md bg-slate-900 px-3 py-2 text-xs leading-relaxed text-white shadow-lg"
                :style="tipStyle"
            >
                {{ text }}
            </span>
        </Teleport>
    </span>
</template>
