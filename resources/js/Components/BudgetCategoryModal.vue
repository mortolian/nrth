<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import DialogModal from '@/Components/DialogModal.vue';
import { useToast } from '@/Composables/useToast';

type CategoryInput = {
    id: number;
    name: string;
    account_id: number | null;
} | null;

const props = defineProps<{
    show: boolean;
    budgetId: number;
    budgetCurrency: string;
    category: CategoryInput;
    expenseAccounts: Array<{ id: number; name: string }>;
}>();

const emit = defineEmits<{
    close: [];
}>();

const toast = useToast();
const processing = ref(false);
const errors = reactive<Record<string, string>>({});

const form = reactive({
    name: '',
    account_id: '' as string,
});

const isEditing = computed(() => props.category != null);

const accountOptions = computed(() => [
    { label: 'Do not track spending', value: '' },
    ...props.expenseAccounts.map((a) => ({ label: a.name, value: String(a.id) })),
]);

watch(
    () => [props.show, props.category] as const,
    ([show]) => {
        if (!show) return;
        Object.keys(errors).forEach((k) => delete errors[k]);
        if (props.category) {
            form.name = props.category.name;
            form.account_id = props.category.account_id != null ? String(props.category.account_id) : '';
        } else {
            form.name = '';
            form.account_id = '';
        }
    },
);

const close = () => {
    if (processing.value) return;
    emit('close');
};

const submit = () => {
    if (processing.value) return;
    Object.keys(errors).forEach((k) => delete errors[k]);

    const name = form.name.trim();
    if (!name) {
        errors.name = 'Category name is required.';
        return;
    }

    const payload = {
        name,
        account_id: form.account_id === '' ? null : Number(form.account_id),
    };

    const visitOptions = {
        preserveScroll: true,
        onStart: () => {
            processing.value = true;
        },
        onSuccess: () => {
            toast.success(isEditing.value ? 'Category updated.' : 'Category added.');
            emit('close');
        },
        onError: (serverErrors: Record<string, string>) => {
            Object.assign(errors, serverErrors);
            if (!Object.keys(serverErrors).length) {
                toast.error('Could not save this category.');
            }
        },
        onFinish: () => {
            processing.value = false;
        },
    };

    if (isEditing.value && props.category) {
        router.put(
            route('budgeting.categories.update', [props.budgetId, props.category.id]),
            payload,
            visitOptions,
        );
        return;
    }

    router.post(route('budgeting.categories.store', props.budgetId), payload, visitOptions);
};
</script>

<template>
    <DialogModal :show="show" max-width="lg" @close="close">
        <template #title>
            {{ isEditing ? 'Edit category' : 'Add category' }}
        </template>
        <template #content>
            <div class="space-y-4 text-left text-slate-900">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        Name <span class="text-rose-600">*</span>
                    </label>
                    <AppInput v-model="form.name" placeholder="e.g. Operations" />
                    <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Track spending</label>
                    <AppSelect
                        :model-value="form.account_id"
                        :options="accountOptions"
                        @update:model-value="form.account_id = $event"
                    />
                    <p class="mt-1 text-xs text-slate-500">
                        Optional — link an expense account to compare ledger spend with this category’s planned line
                        items for the budget period ({{ budgetCurrency }}).
                    </p>
                    <p v-if="errors.account_id" class="mt-1 text-xs text-rose-600">{{ errors.account_id }}</p>
                </div>
            </div>
        </template>
        <template #footer>
            <div class="flex justify-end gap-2">
                <AppButton variant="secondary" :disabled="processing" @click="close">Cancel</AppButton>
                <AppButton variant="primary" :disabled="processing" @click="submit">
                    {{ isEditing ? 'Save category' : 'Add category' }}
                </AppButton>
            </div>
        </template>
    </DialogModal>
</template>
