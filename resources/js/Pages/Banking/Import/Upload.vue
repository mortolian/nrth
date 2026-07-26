<script setup lang="ts">
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
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

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    form.file = input.files?.[0] ?? null;
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
        <AppCard class="mt-5">
            <form class="grid max-w-xl gap-5" @submit.prevent="submit">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-500">Account</label>
                    <AppSelect
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

                <div>
                    <label class="mb-1.5 block text-xs font-medium text-slate-500">Statement file</label>
                    <input
                        type="file"
                        accept=".csv,.txt,.ofx,text/csv,text/plain,application/x-ofx"
                        class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                        @change="onFileChange"
                    >
                    <p class="mt-1.5 text-xs text-slate-500">CSV, TXT, or OFX — max 10MB.</p>
                    <p v-if="form.errors.file" class="mt-1.5 text-xs text-red-600">{{ form.errors.file }}</p>
                </div>

                <FormActions bordered>
                    <AppButton
                        type="submit"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="!accounts.length"
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
