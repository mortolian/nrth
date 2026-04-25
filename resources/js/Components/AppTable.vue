<script setup lang="ts">
defineProps<{
    columns?: string[];
}>();
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead v-if="columns?.length" class="bg-slate-50">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column"
                            class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
                        >
                            {{ column }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <slot />
                </tbody>
            </table>
        </div>
    </div>
</template>
