<script setup>
import Modal from './Modal.vue';

const emit = defineEmits(['close']);

defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const close = () => {
    emit('close');
};
</script>

<template>
    <Modal
        :show="show"
        :max-width="maxWidth"
        :closeable="closeable"
        @close="close"
    >
        <div class="px-6 py-5">
            <div class="text-base font-semibold text-slate-900">
                <slot name="title" />
            </div>

            <div class="mt-3 text-sm text-slate-600">
                <slot name="content" />
            </div>
        </div>

        <div class="flex flex-row justify-end gap-0 border-t border-slate-100 bg-slate-50 px-6 py-4 text-end">
            <slot name="footer" />
        </div>
    </Modal>
</template>
