<script setup lang="ts">
import { onMounted } from 'vue';

const props = defineProps<{
    action: string;
    fields: Record<string, string>;
}>();

onMounted(() => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = props.action;
    form.style.display = 'none';
    for (const [name, value] of Object.entries(props.fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
});
</script>

<template>
    <div class="flex min-h-[40vh] items-center justify-center p-8 text-sm text-slate-600">
        Redirecting to PayFast…
    </div>
</template>
