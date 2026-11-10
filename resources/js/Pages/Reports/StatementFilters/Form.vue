<script setup lang="ts">
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import FormActions from '@/Components/FormActions.vue';
import { useReportsTabs } from '@/Composables/useFeatureTabs';

const reportsTabs = useReportsTabs();

type Rule = { alias: string; match: string };

const props = defineProps<{
    report: { id: number; name: string; rules: Rule[] } | null;
}>();

const editing = computed(() => props.report !== null);

const form = useForm({
    name: props.report?.name ?? '',
    rules: (props.report?.rules?.length ? props.report.rules : [{ alias: '', match: '' }]).map((rule) => ({
        alias: rule.alias,
        match: rule.match,
    })),
});

const addRule = () => {
    form.rules.push({ alias: '', match: '' });
};

const removeRule = (index: number) => {
    if (form.rules.length === 1) {
        form.rules[0] = { alias: '', match: '' };
        return;
    }
    form.rules.splice(index, 1);
};

const ruleError = (index: number, field: 'alias' | 'match') => {
    const errors = form.errors as Record<string, string>;
    return errors[`rules.${index}.${field}`] ?? '';
};

const save = () => {
    if (editing.value && props.report) {
        form.put(route('reports.statement-filters.update', props.report.id));
        return;
    }
    form.post(route('reports.statement-filters.store'));
};

const destroyReport = () => {
    if (!props.report) {
        return;
    }
    if (!window.confirm(`Delete “${props.report.name}”? The imported statement lines stay.`)) {
        return;
    }
    router.delete(route('reports.statement-filters.destroy', props.report.id));
};
</script>

<template>
    <FeatureShell
        title="Reports"
        section="statement-filters"
        :tabs="reportsTabs"
        :document-title="editing ? 'Edit statement filters' : 'New statement filters'"
        subtitle="Each row is a contains match on the statement description or reference"
    >
        <AppCard>
            <form @submit.prevent="save">
                <div class="max-w-xl">
                    <label class="mb-1 block text-xs font-medium text-slate-500" for="report-name">Report name</label>
                    <AppInput id="report-name" v-model="form.name" placeholder="Household spend" />
                    <p v-if="form.errors.name" class="mt-1.5 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>

                <div class="mt-6 space-y-3">
                    <div
                        v-for="(rule, index) in form.rules"
                        :key="index"
                        class="grid gap-3 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto] md:items-start"
                    >
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Alias</label>
                            <AppInput v-model="rule.alias" placeholder="Woolies" />
                            <p v-if="ruleError(index, 'alias')" class="mt-1.5 text-xs text-red-600">{{ ruleError(index, 'alias') }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Contains</label>
                            <AppInput v-model="rule.match" placeholder="Woolworths" />
                            <p v-if="ruleError(index, 'match')" class="mt-1.5 text-xs text-red-600">{{ ruleError(index, 'match') }}</p>
                        </div>
                        <div class="md:pt-5">
                            <AppButton type="button" variant="ghost" @click="removeRule(index)">Remove</AppButton>
                        </div>
                    </div>
                </div>

                <p v-if="form.errors.rules" class="mt-3 text-xs text-red-600">{{ form.errors.rules }}</p>

                <div class="mt-4">
                    <AppButton type="button" variant="secondary" @click="addRule">Add filter</AppButton>
                </div>

                <FormActions bordered>
                    <AppButton type="submit" :disabled="form.processing">{{ editing ? 'Update' : 'Create' }}</AppButton>
                    <AppButton
                        as="a"
                        variant="secondary"
                        :href="editing && report ? route('reports.statement-filters.show', report.id) : route('reports.statement-filters.index')"
                    >
                        Cancel
                    </AppButton>
                    <AppButton v-if="editing" type="button" variant="danger" @click="destroyReport">Delete</AppButton>
                </FormActions>
            </form>
        </AppCard>
    </FeatureShell>
</template>
