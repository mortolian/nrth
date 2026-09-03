<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Upload } from 'lucide-vue-next';
import FeatureShell from '@/Components/FeatureShell.vue';
import { useBankingTabs } from '@/Composables/useFeatureTabs';

const bankingTabs = useBankingTabs();

type AccountOption = {
    id: number;
    name: string;
    bank_name: string | null;
    currency: string;
};

const props = defineProps<{
    accounts: AccountOption[];
}>();

const form = useForm<{
    account_id: number | '';
    file: File | null;
}>({
    account_id: '',
    file: null,
});

const accountSelectOptions = computed(() =>
    props.accounts.map((account) => ({
        label: account.bank_name ? `${account.name} (${account.bank_name})` : account.name,
        value: String(account.id),
    })),
);

const dropActive = ref(false);
const fileError = ref<string | null>(null);

const acceptedExtensions = new Set(['csv', 'txt', 'ofx']);

const isAcceptedFile = (file: File) => {
    const name = file.name.toLowerCase();
    const ext = name.includes('.') ? name.slice(name.lastIndexOf('.') + 1) : '';
    if (ext && acceptedExtensions.has(ext)) {
        return true;
    }
    const type = (file.type || '').toLowerCase();

    return (
        type === 'text/csv'
        || type === 'text/plain'
        || type === 'application/x-ofx'
        || type === 'application/ofx'
        || type === 'application/vnd.intu.qbo'
    );
};

const setFile = (file: File | null) => {
    fileError.value = null;
    form.clearErrors('file');
    form.file = file;
};

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    if (file && !isAcceptedFile(file)) {
        setFile(null);
        fileError.value = 'Upload a CSV, TXT, or OFX file.';
        input.value = '';
        return;
    }
    setFile(file);
    input.value = '';
};

const onDragEnter = (event: DragEvent) => {
    event.preventDefault();
    dropActive.value = true;
};

const onDragOver = (event: DragEvent) => {
    event.preventDefault();
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'copy';
    }
    dropActive.value = true;
};

const onDragLeave = (event: DragEvent) => {
    event.preventDefault();
    const current = event.currentTarget as HTMLElement | null;
    const related = event.relatedTarget as Node | null;
    if (current && related && current.contains(related)) {
        return;
    }
    dropActive.value = false;
};

const onDrop = (event: DragEvent) => {
    event.preventDefault();
    dropActive.value = false;
    const file = event.dataTransfer?.files?.[0] ?? null;
    if (!file) {
        return;
    }
    if (!isAcceptedFile(file)) {
        setFile(null);
        fileError.value = 'Upload a CSV, TXT, or OFX file.';
        return;
    }
    setFile(file);
};

const clearFile = () => {
    setFile(null);
};

const formatFileSize = (bytes: number) => {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const submit = () => {
    form.post(route('banking.import.store'), {
        forceFormData: true,
    });
};
</script>

<template>
    <FeatureShell
        title="Banking"
        section="import"
        :tabs="bankingTabs"
        document-title="Import bank statement"
        subtitle="Upload a CSV, TXT, or OFX statement for a banking account."
    >
        <AppCard class="overflow-hidden p-0">
            <form class="w-full" @submit.prevent="submit">
                <div class="space-y-5 px-5 py-5">
                    <div class="w-full">
                        <label class="mb-1.5 block text-xs font-medium text-slate-500">Account</label>
                        <AppSelect
                            class="w-full"
                            :model-value="form.account_id === '' ? '' : String(form.account_id)"
                            :options="accountSelectOptions"
                            placeholder="Select account"
                            :disabled="!accounts.length"
                            @update:model-value="form.account_id = $event === '' ? '' : Number($event)"
                        />
                        <p v-if="form.errors.account_id" class="mt-1.5 text-xs text-red-600">{{ form.errors.account_id }}</p>
                        <p v-if="!accounts.length" class="mt-1.5 text-xs text-amber-700">
                            Create a banking account before uploading a statement.
                        </p>
                    </div>

                    <div class="w-full">
                        <p class="mb-1.5 text-xs font-medium text-slate-500">Statement file</p>
                        <label
                            class="flex min-h-48 w-full cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-6 py-10 text-center transition"
                            :class="dropActive
                                ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/20'
                                : 'border-slate-300 bg-slate-50 hover:border-slate-400 hover:bg-slate-100'"
                            @dragenter="onDragEnter"
                            @dragover="onDragOver"
                            @dragleave="onDragLeave"
                            @drop="onDrop"
                        >
                            <Upload class="h-6 w-6 shrink-0 text-slate-500" />
                            <span class="text-sm font-medium text-slate-800">
                                {{
                                    dropActive
                                        ? 'Drop to upload'
                                        : form.file
                                            ? 'Replace file'
                                            : 'Drop statement here'
                                }}
                            </span>
                            <span class="text-xs text-slate-500">
                                CSV, TXT, or OFX — click or drag · max 10MB
                            </span>
                            <input
                                type="file"
                                accept=".csv,.txt,.ofx,text/csv,text/plain,application/x-ofx"
                                class="hidden"
                                @change="onFileChange"
                            >
                        </label>
                        <div
                            v-if="form.file"
                            class="mt-3 flex w-full items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900">{{ form.file.name }}</p>
                                <p class="text-xs text-slate-500">{{ formatFileSize(form.file.size) }}</p>
                            </div>
                            <AppButton type="button" variant="ghost" size="sm" @click="clearFile">
                                Remove
                            </AppButton>
                        </div>
                        <p v-if="fileError" class="mt-1.5 text-xs text-red-600">{{ fileError }}</p>
                        <p v-if="form.errors.file" class="mt-1.5 text-xs text-red-600">{{ form.errors.file }}</p>
                    </div>
                </div>

                <FormActions bordered class="mt-0 px-5 pb-5">
                    <AppButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="!accounts.length || !form.file"
                    >
                        {{ form.processing ? 'Uploading…' : 'Continue' }}
                    </AppButton>
                    <AppButton
                        type="button"
                        variant="secondary"
                        :disabled="form.processing"
                        @click="router.visit(route('banking.accounts.index'))"
                    >
                        Cancel
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </FeatureShell>
</template>
