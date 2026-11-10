<script setup lang="ts">
import axios from 'axios';
import { ChevronDown } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const props = defineProps<{
    match: string;
    currency: string;
}>();

type Sample = { description: string; count: number };

const visible = ref(false);
const count = ref(0);
const totalCents = ref(0);
const samples = ref<Sample[]>([]);

let timer: ReturnType<typeof setTimeout> | undefined;
let requestId = 0;

watch(
    () => props.match,
    (value) => {
        window.clearTimeout(timer);
        const needle = value.trim();
        if (needle.length < 3) {
            visible.value = false;
            samples.value = [];
            return;
        }

        timer = setTimeout(() => {
            const current = ++requestId;
            axios
                .get(route('reports.statement-filters.preview'), { params: { match: needle } })
                .then((response) => {
                    if (current !== requestId) {
                        return;
                    }
                    count.value = Number(response.data.count) || 0;
                    totalCents.value = Number(response.data.total_cents) || 0;
                    samples.value = Array.isArray(response.data.samples) ? response.data.samples : [];
                    visible.value = true;
                })
                .catch(() => {
                    if (current !== requestId) {
                        return;
                    }
                    visible.value = false;
                });
        }, 300);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    window.clearTimeout(timer);
    requestId += 1;
});

const formatTotal = () => useFormatCurrency((Number(totalCents.value) || 0) / 100, props.currency || 'ZAR');
</script>

<template>
    <div v-if="visible" class="mt-1.5 text-xs text-slate-500">
        <p v-if="count === 0">No imported lines contain this.</p>
        <template v-else>
            <p>{{ count }} {{ count === 1 ? 'line' : 'lines' }} · {{ formatTotal() }}</p>
            <details
                v-if="samples.length"
                class="group mt-1.5 overflow-hidden rounded-md border border-slate-200 bg-white"
            >
                <summary class="flex cursor-pointer list-none items-center justify-between px-2 py-1.5 text-xs font-medium text-slate-600 [&::-webkit-details-marker]:hidden">
                    Transactions
                    <ChevronDown class="h-3.5 w-3.5 text-slate-400 transition group-open:rotate-180" aria-hidden="true" />
                </summary>
                <ul class="space-y-1 border-t border-slate-100 px-2 py-1.5">
                    <li v-for="sample in samples" :key="sample.description" class="text-slate-600">
                        {{ sample.description }}
                        <span v-if="sample.count > 1" class="text-slate-400">· {{ sample.count }}</span>
                    </li>
                </ul>
            </details>
        </template>
    </div>
</template>
