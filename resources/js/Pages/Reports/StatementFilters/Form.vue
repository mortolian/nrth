<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'radix-vue';
import FeatureShell from '@/Components/FeatureShell.vue';
import FormActions from '@/Components/FormActions.vue';
import { useReportsTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/composables/useFormatCurrency';
import MatchPreview from '@/Pages/Reports/StatementFilters/MatchPreview.vue';

const reportsTabs = useReportsTabs();

type Rule = { alias: string; match: string };

type Suggestion = {
    alias: string;
    match: string;
    count: number;
    total_cents: number;
    examples: string[];
};

const props = withDefaults(defineProps<{
    report: { id: number; name: string; rules: Rule[] } | null;
    suggestions?: Suggestion[];
    currency?: string;
}>(), {
    suggestions: () => [],
    currency: 'ZAR',
});

const editing = computed(() => props.report !== null);
const suggestionsOpen = ref(false);

const form = useForm({
    name: props.report?.name ?? '',
    rules: (props.report?.rules?.length ? props.report.rules : [{ alias: '', match: '' }]).map((rule) => ({
        alias: rule.alias,
        match: rule.match,
    })),
});

const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, props.currency || 'ZAR');

const usedMatches = computed(() => new Set(
    form.rules
        .map((rule) => rule.match.trim().toLowerCase())
        .filter((match) => match !== ''),
));

const addRule = () => {
    form.rules.push({ alias: '', match: '' });
};

const addSuggestion = (suggestion: Suggestion) => {
    if (usedMatches.value.has(suggestion.match)) {
        return;
    }
    const blank = form.rules.find((rule) => rule.alias.trim() === '' && rule.match.trim() === '');
    if (blank) {
        blank.alias = suggestion.alias;
        blank.match = suggestion.match;
        return;
    }
    form.rules.push({ alias: suggestion.alias, match: suggestion.match });
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
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500" for="report-name">Report name</label>
                    <AppInput id="report-name" v-model="form.name" placeholder="Household spend" />
                    <p v-if="form.errors.name" class="mt-1.5 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>

                <div v-if="suggestions.length" class="mt-6">
                    <PopoverRoot v-model:open="suggestionsOpen">
                        <PopoverTrigger as-child>
                            <button
                                type="button"
                                class="flex h-11 w-full items-center justify-between gap-3 rounded-lg border border-slate-300 bg-white px-3 text-left outline-none transition hover:border-slate-400 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/25"
                            >
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-slate-900">From your statements</span>
                                    <span class="block truncate text-xs text-slate-500">
                                        {{ suggestions.length }} {{ suggestions.length === 1 ? 'repeated merchant' : 'repeated merchants' }}
                                    </span>
                                </span>
                                <ChevronDown
                                    class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
                                    :class="suggestionsOpen ? 'rotate-180' : ''"
                                    aria-hidden="true"
                                />
                            </button>
                        </PopoverTrigger>
                        <PopoverPortal>
                            <PopoverContent
                                align="start"
                                :side-offset="6"
                                class="z-[200] max-h-96 w-[var(--radix-popover-trigger-width)] overflow-y-auto rounded-lg border border-slate-200 bg-white p-2 shadow-lg outline-none"
                            >
                                <p class="px-2 pb-2 pt-1 text-xs text-slate-500">
                                    Repeated wording, with card numbers, store numbers, and dates removed.
                                </p>
                                <ul class="space-y-1">
                                    <li
                                        v-for="suggestion in suggestions"
                                        :key="suggestion.match"
                                        class="rounded-md px-2 py-2.5"
                                        :class="usedMatches.has(suggestion.match) ? 'bg-slate-50' : 'hover:bg-slate-50'"
                                    >
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold text-slate-900">{{ suggestion.alias }}</p>
                                                <p class="mt-0.5 text-xs text-slate-500">
                                                    {{ suggestion.count }} lines · {{ formatCents(suggestion.total_cents) }}
                                                </p>
                                                <p class="text-xs text-slate-400">Contains “{{ suggestion.match }}”</p>
                                            </div>
                                            <AppButton
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                :disabled="usedMatches.has(suggestion.match)"
                                                @click="addSuggestion(suggestion)"
                                            >
                                                {{ usedMatches.has(suggestion.match) ? 'Added' : 'Add' }}
                                            </AppButton>
                                        </div>
                                        <details
                                            v-if="suggestion.examples.length"
                                            class="group mt-2 overflow-hidden rounded-md border border-slate-200 bg-white"
                                        >
                                            <summary class="flex cursor-pointer list-none items-center justify-between px-2 py-1.5 text-xs font-medium text-slate-600 [&::-webkit-details-marker]:hidden">
                                                Statement lines
                                                <ChevronDown class="h-3.5 w-3.5 text-slate-400 transition group-open:rotate-180" aria-hidden="true" />
                                            </summary>
                                            <ul class="space-y-1 border-t border-slate-100 px-2 py-1.5">
                                                <li
                                                    v-for="example in suggestion.examples"
                                                    :key="example"
                                                    class="truncate text-xs text-slate-600"
                                                >
                                                    {{ example }}
                                                </li>
                                            </ul>
                                        </details>
                                    </li>
                                </ul>
                            </PopoverContent>
                        </PopoverPortal>
                    </PopoverRoot>
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
                            <MatchPreview :match="rule.match" :currency="currency" />
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
