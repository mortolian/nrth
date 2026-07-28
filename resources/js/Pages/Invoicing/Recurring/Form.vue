<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FieldHelp from '@/Components/FieldHelp.vue';
import FormValidationBanner from '@/Components/FormValidationBanner.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useToast } from '@/Composables/useToast';

type ClientOption = { id: number; name: string; currency: string; payment_terms_days: number };
type CatalogItem = {
    id: number;
    name: string;
    description: string | null;
    unit_price_cents: number;
    default_vat_rate: number | null;
};
type Line = {
    description: string;
    quantity: number | string;
    unit_price: string;
    vat_rate: number | string;
    item_id?: number | null;
};

const props = defineProps<{
    isEditing: boolean;
    recurring: null | Record<string, any>;
    clients: ClientOption[];
    items: CatalogItem[];
    charges_vat: boolean;
    default_currency: string;
    tax_rates: Array<{ id: number; name: string; rate: number; is_default: boolean }>;
}>();

const toast = useToast();
const page = usePage();
const { fieldErrors, setFromServer, clear, messages: clientErrorMessages } = useFieldErrors();
const currencyOptions = computed(
    () => (page.props.currencyOptions as Array<{ value: string; label: string }>) ?? [],
);
const defaultVat = computed(() => {
    if (!props.charges_vat) return 0;
    return props.tax_rates.find((r) => r.is_default)?.rate ?? props.tax_rates[0]?.rate ?? 0;
});

const help = {
    client: 'Who this template bills each cycle.',
    frequency: 'How often a new invoice is created from this template.',
    nextRun: 'First (or next) date the daily job will pick this up. That day becomes the invoice date.',
    weekday: 'ISO weekday (1=Mon … 7=Sun). Invoice is generated and optionally emailed that day.',
    generateDay: 'Day of month (1–28), unless you use last day of month.',
    generateMonth: 'Calendar month for yearly schedules.',
    periodOffset:
        'Shifts month placeholders vs the invoice date. Example: −1 = bill June when issuing on 1 July. Example: Rent for {{month_year}} → Rent for July 2026.',
    dueRule: 'How due date is recalculated every run (not copied from the last invoice).',
    dueDays: 'Number of days after the invoice (generate/send) date.',
    dueOnDay: 'Day of month (1–28) for the selected due-date rule.',
    limits: 'When the schedule stops: never, after N invoices, or after an end date.',
    lines:
        'Use tokens like {{month_year}}, {{month}}, {{year}}, {{issue_date}}, {{due_date}}, {{day}}. They resolve when each invoice is generated.',
};

const mapLines = (raw: any[] | undefined): Line[] => {
    if (!raw?.length) {
        return [{
            description: 'Rent for {{month_year}}',
            quantity: 1,
            unit_price: '0.00',
            vat_rate: defaultVat.value,
            item_id: null,
        }];
    }

    return raw.map((line) => ({
        description: String(line.description ?? ''),
        quantity: Number(line.quantity) || 1,
        unit_price: ((Number(line.unit_price_cents) || 0) / 100).toFixed(2),
        vat_rate: Number(line.vat_rate) || defaultVat.value,
        item_id: line.item_id ?? null,
    }));
};

const form = ref({
    client_id: props.recurring?.client_id ?? props.clients[0]?.id ?? null,
    frequency: props.recurring?.frequency ?? 'monthly',
    generate_on_weekday: props.recurring?.generate_on_weekday ?? 1,
    generate_on_day: props.recurring?.generate_on_day ?? 1,
    generate_on_last_day: Boolean(props.recurring?.generate_on_last_day ?? false),
    generate_on_month: props.recurring?.generate_on_month ?? 1,
    limit_type: props.recurring?.limit_type ?? 'none',
    limit_count: props.recurring?.limit_count ?? null,
    limit_end_date: props.recurring?.limit_end_date ?? null,
    next_run_date: props.recurring?.next_run_date ?? new Date().toISOString().slice(0, 10),
    auto_send: Boolean(props.recurring?.auto_send ?? false),
    period_offset_months: props.recurring?.period_offset_months ?? 0,
    due_date_rule: props.recurring?.due_date_rule ?? 'client_terms',
    due_days: props.recurring?.due_days ?? 10,
    due_on_day: props.recurring?.due_on_day ?? 1,
    currency: props.recurring?.currency ?? props.default_currency ?? 'ZAR',
    reference: props.recurring?.reference ?? '',
    notes: props.recurring?.notes ?? '',
    footer: props.recurring?.footer ?? '',
    line_items: mapLines(props.recurring?.line_items as any[] | undefined),
});

const saving = ref(false);
const hasClients = computed(() => props.clients.length > 0);

const inertiaErrorMessages = computed(() => {
    const raw = page.props.errors as Record<string, string | string[] | undefined>;
    if (!raw || typeof raw !== 'object') {
        return [] as string[];
    }
    return Object.values(raw).flatMap((val) => {
        if (val === undefined || val === null) {
            return [];
        }
        return [Array.isArray(val) ? val.join(' ') : String(val)];
    });
});

const visibleValidationErrors = computed(() =>
    clientErrorMessages.value.length ? clientErrorMessages.value : inertiaErrorMessages.value,
);

const applyItem = (index: number, raw: string) => {
    if (!raw) {
        form.value.line_items[index].item_id = null;
        return;
    }
    const item = props.items.find((i) => i.id === Number(raw));
    if (!item) return;
    const line = form.value.line_items[index];
    line.item_id = item.id;
    line.description = (item.description && item.description.trim()) ? item.description : item.name;
    line.unit_price = (item.unit_price_cents / 100).toFixed(2);
    line.vat_rate = props.charges_vat ? (item.default_vat_rate ?? defaultVat.value) : 0;
};

const addLine = () => {
    form.value.line_items.push({
        description: '',
        quantity: 1,
        unit_price: '0.00',
        vat_rate: defaultVat.value,
        item_id: null,
    });
};

const removeLine = (index: number) => {
    if (form.value.line_items.length <= 1) return;
    form.value.line_items.splice(index, 1);
};

const normalizeMoneyInput = (raw: unknown): string => {
    const cleaned = String(raw ?? '').trim().replace(',', '.');
    if (cleaned === '') return '0.00';
    const parsed = Number(cleaned);
    if (!Number.isFinite(parsed) || parsed < 0) return '0.00';
    return parsed.toFixed(2);
};

const onUnitPriceBlur = (index: number) => {
    const line = form.value.line_items[index];
    if (!line) return;
    line.unit_price = normalizeMoneyInput(line.unit_price);
};

const placeholderTokens = [
    { token: '{{month_year}}', label: '{{month_year}}' },
    { token: '{{month}}', label: '{{month}}' },
    { token: '{{issue_date}}', label: '{{issue_date}}' },
    { token: '{{due_date}}', label: '{{due_date}}' },
] as const;

const insertToken = (token: string) => {
    const line = form.value.line_items[0];
    if (!line) return;
    line.description = `${line.description || ''}${token}`;
};

const submit = () => {
    if (saving.value) return;
    if (!hasClients.value || !form.value.client_id) {
        toast.error('Create a client before saving a recurring invoice.');
        return;
    }

    clear();

    const payload = {
        client_id: Number(form.value.client_id),
        frequency: form.value.frequency,
        generate_on_weekday: form.value.frequency === 'weekly'
            ? Number(form.value.generate_on_weekday)
            : null,
        generate_on_day: form.value.frequency === 'weekly' || form.value.generate_on_last_day
            ? null
            : Number(form.value.generate_on_day),
        generate_on_last_day: Boolean(form.value.generate_on_last_day),
        generate_on_month: form.value.frequency === 'yearly'
            ? Number(form.value.generate_on_month)
            : null,
        limit_type: form.value.limit_type,
        limit_count: form.value.limit_type === 'count' ? Number(form.value.limit_count) : null,
        limit_end_date: form.value.limit_type === 'end_date' ? form.value.limit_end_date : null,
        next_run_date: form.value.next_run_date,
        auto_send: Boolean(form.value.auto_send),
        period_offset_months: Number(form.value.period_offset_months) || 0,
        due_date_rule: form.value.due_date_rule,
        due_days: form.value.due_date_rule === 'days_after_issue'
            ? Number(form.value.due_days)
            : null,
        due_on_day: ['day_of_month', 'day_of_next_month'].includes(form.value.due_date_rule)
            ? Number(form.value.due_on_day)
            : null,
        currency: form.value.currency,
        reference: form.value.reference || null,
        notes: form.value.notes || null,
        footer: form.value.footer || null,
        line_items: form.value.line_items.map((line) => ({
            description: String(line.description || '').trim(),
            quantity: Number(line.quantity),
            unit_price_cents: Math.round(Number(normalizeMoneyInput(line.unit_price)) * 100),
            vat_rate: props.charges_vat ? Number(line.vat_rate) : 0,
            item_id: line.item_id ?? null,
        })),
    };

    const opts = {
        onStart: () => {
            saving.value = true;
        },
        onFinish: () => {
            saving.value = false;
        },
        onSuccess: () => {
            toast.success(props.isEditing ? 'Recurring invoice saved.' : 'Recurring invoice created.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
            if (!Object.keys(errors).length) {
                toast.error('Could not save this recurring invoice.');
            }
        },
    };

    if (props.isEditing && props.recurring?.id) {
        router.put(route('invoicing.recurring.update', props.recurring.id), payload, opts);
        return;
    }

    router.post(route('invoicing.recurring.store'), payload, opts);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit recurring' : 'New recurring'"
        :breadcrumbs="[
            { label: 'Money In', href: route('invoicing.invoices.index') },
            { label: 'Recurring', href: route('invoicing.recurring.index') },
            { label: isEditing ? 'Edit' : 'New' },
        ]"
    >
        <PageHeader
            :title="isEditing ? 'Edit recurring invoice' : 'New recurring invoice'"
            subtitle="Schedule, placeholders, and due-date rules"
        />

        <FormValidationBanner
            class="mt-4"
            title="Could not save recurring invoice"
            :errors="visibleValidationErrors"
        />

        <AppCard class="mt-5 max-w-3xl space-y-6">
            <section class="space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Client &amp; cadence</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Who gets billed and how often</p>
                </div>
            <div>
                <FieldHelp label="Client" :text="help.client" />
                <div
                    v-if="!hasClients"
                    class="rounded-md border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-950"
                >
                    <p class="font-medium">You need at least one client</p>
                    <p class="mt-1 text-amber-900/90">Create a client first, then set up recurring billing.</p>
                    <button
                        type="button"
                        class="mt-2 inline-block text-sm font-medium text-brand-700 underline hover:text-brand-800"
                        @click="router.visit(route('invoicing.clients.create', { return: '/invoicing/recurring/create' }))"
                    >
                        Create a client
                    </button>
                </div>
                <AppSelect
                    v-else
                    :model-value="form.client_id ? String(form.client_id) : ''"
                    :options="clients.map((c) => ({ label: c.name, value: String(c.id) }))"
                    @update:model-value="form.client_id = Number($event)"
                />
                <p v-if="fieldErrors.client_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.client_id }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <FieldHelp label="Frequency" :text="help.frequency" />
                    <AppSelect
                        :model-value="form.frequency"
                        :options="[
                            { label: 'Weekly', value: 'weekly' },
                            { label: 'Monthly', value: 'monthly' },
                            { label: 'Yearly', value: 'yearly' },
                        ]"
                        @update:model-value="form.frequency = String($event)"
                    />
                </div>
                <div>
                    <FieldHelp label="Next run date" :text="help.nextRun" />
                    <AppInput v-model="form.next_run_date" type="date" />
                    <p v-if="fieldErrors.next_run_date" class="mt-1 text-xs text-rose-600">{{ fieldErrors.next_run_date }}</p>
                </div>
            </div>

            <div v-if="form.frequency === 'weekly'">
                <FieldHelp label="Generate on weekday" :text="help.weekday" />
                <AppSelect
                    :model-value="String(form.generate_on_weekday)"
                    :options="[
                        { label: 'Monday', value: '1' },
                        { label: 'Tuesday', value: '2' },
                        { label: 'Wednesday', value: '3' },
                        { label: 'Thursday', value: '4' },
                        { label: 'Friday', value: '5' },
                        { label: 'Saturday', value: '6' },
                        { label: 'Sunday', value: '7' },
                    ]"
                    @update:model-value="form.generate_on_weekday = Number($event)"
                />
            </div>
            <div v-else class="grid gap-4 sm:grid-cols-2">
                <div>
                    <FieldHelp label="Generate on day" :text="help.generateDay" />
                    <AppInput
                        v-model="form.generate_on_day"
                        type="number"
                        min="1"
                        max="28"
                        :disabled="form.generate_on_last_day"
                    />
                </div>
                <label class="mt-6 flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="form.generate_on_last_day" type="checkbox" class="rounded border-slate-300" />
                    Last day of month
                </label>
                <div v-if="form.frequency === 'yearly'">
                    <FieldHelp label="Generate on month" :text="help.generateMonth" />
                    <AppInput v-model="form.generate_on_month" type="number" min="1" max="12" />
                </div>
            </div>

            <div>
                <FieldHelp label="Period offset (months)" :text="help.periodOffset" />
                <AppInput v-model="form.period_offset_months" type="number" min="-12" max="12" />
            </div>
            </section>

            <section class="space-y-4 border-t border-slate-100 pt-6">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Due dates &amp; limits</h3>
                    <p class="mt-0.5 text-xs text-slate-500">How each invoice is dated and when the schedule ends</p>
                </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <FieldHelp label="Due date rule" :text="help.dueRule" />
                    <AppSelect
                        :model-value="form.due_date_rule"
                        :options="[
                            { label: 'Client payment terms', value: 'client_terms' },
                            { label: 'Days after issue', value: 'days_after_issue' },
                            { label: 'Day of month', value: 'day_of_month' },
                            { label: 'Day of next month', value: 'day_of_next_month' },
                            { label: 'Last day of month', value: 'last_day_of_month' },
                            { label: 'Last day of next month', value: 'last_day_of_next_month' },
                        ]"
                        @update:model-value="form.due_date_rule = String($event)"
                    />
                </div>
                <div v-if="form.due_date_rule === 'days_after_issue'">
                    <FieldHelp label="Due days" :text="help.dueDays" />
                    <AppInput v-model="form.due_days" type="number" min="0" />
                </div>
                <div v-else-if="form.due_date_rule === 'day_of_month' || form.due_date_rule === 'day_of_next_month'">
                    <FieldHelp label="Due on day" :text="help.dueOnDay" />
                    <AppInput v-model="form.due_on_day" type="number" min="1" max="28" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <FieldHelp label="Limits" :text="help.limits" />
                    <AppSelect
                        :model-value="form.limit_type"
                        :options="[
                            { label: 'None', value: 'none' },
                            { label: 'Count', value: 'count' },
                            { label: 'End date', value: 'end_date' },
                        ]"
                        @update:model-value="form.limit_type = String($event)"
                    />
                </div>
                <div v-if="form.limit_type === 'count'">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Limit count</label>
                    <AppInput v-model="form.limit_count" type="number" min="1" />
                </div>
                <div v-if="form.limit_type === 'end_date'">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Limit end date</label>
                    <AppInput v-model="form.limit_end_date" type="date" />
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input v-model="form.auto_send" type="checkbox" class="rounded border-slate-300" />
                <span>
                    Auto-send
                    <span class="text-slate-500"> — email the client when generated; otherwise leave a draft.</span>
                </span>
            </label>
            </section>

            <section class="space-y-4 border-t border-slate-100 pt-6">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Line items</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Template lines copied onto each generated invoice</p>
                </div>
            <div>
                <FieldHelp label="Placeholders" :text="help.lines" />
                <div class="mb-2 flex flex-wrap gap-2">
                    <AppButton
                        v-for="item in placeholderTokens"
                        :key="item.token"
                        size="sm"
                        variant="ghost"
                        type="button"
                        @click="insertToken(item.token)"
                    >
                        {{ item.label }}
                    </AppButton>
                </div>
                <div
                    v-for="(line, index) in form.line_items"
                    :key="index"
                    class="mb-3 space-y-2 rounded-md border border-slate-200 bg-slate-50/50 p-3"
                >
                    <AppSelect
                        v-if="items.length"
                        :model-value="line.item_id ? String(line.item_id) : ''"
                        :options="[{ label: 'Catalog item…', value: '' }, ...items.map((i) => ({ label: i.name, value: String(i.id) }))]"
                        @update:model-value="applyItem(index, String($event ?? ''))"
                    />
                    <textarea
                        v-model="line.description"
                        rows="2"
                        placeholder="Description (placeholders allowed)"
                        class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                    />
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="mb-1 block text-[11px] font-medium text-slate-500">Qty</label>
                            <AppInput v-model="line.quantity" type="number" step="0.01" min="0" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium text-slate-500">Unit price</label>
                            <AppInput
                                v-model="line.unit_price"
                                type="text"
                                inputmode="decimal"
                                class="tabular-nums"
                                @blur="onUnitPriceBlur(index)"
                            />
                        </div>
                        <div v-if="charges_vat">
                            <label class="mb-1 block text-[11px] font-medium text-slate-500">VAT rate</label>
                            <AppSelect
                                :model-value="String(line.vat_rate)"
                                :options="tax_rates.length
                                    ? tax_rates.map((r) => ({ label: r.name, value: String(r.rate) }))
                                    : [{ label: 'No VAT', value: '0' }]"
                                @update:model-value="line.vat_rate = Number($event)"
                            />
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <AppButton
                            v-if="form.line_items.length > 1"
                            size="sm"
                            variant="ghost"
                            type="button"
                            @click="removeLine(index)"
                        >
                            Remove line
                        </AppButton>
                    </div>
                </div>
                <AppButton size="sm" variant="secondary" type="button" @click="addLine">Add line</AppButton>
                <p v-if="fieldErrors.line_items" class="mt-1 text-xs text-rose-600">{{ fieldErrors.line_items }}</p>
            </div>
            </section>

            <section class="space-y-4 border-t border-slate-100 pt-6">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Document defaults</h3>
                    <p class="mt-0.5 text-xs text-slate-500">Currency, reference, notes, and footer</p>
                </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Currency</label>
                    <AppSelect
                        :model-value="form.currency"
                        :options="currencyOptions.length ? currencyOptions : [{ label: form.currency, value: form.currency }]"
                        @update:model-value="form.currency = String($event)"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Reference</label>
                    <AppInput v-model="form.reference" placeholder="Optional (placeholders ok)" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        placeholder="Optional notes (placeholders ok)"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Footer</label>
                    <textarea
                        v-model="form.footer"
                        rows="3"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        placeholder="Optional footer / terms"
                    />
                </div>
            </div>
            </section>

            <FormActions bordered>
                <AppButton
                    variant="primary"
                    :disabled="saving || !hasClients"
                    :loading="saving"
                    @click="submit"
                >
                    {{ isEditing ? 'Update' : 'Save' }}
                </AppButton>
                <AppButton variant="secondary" @click="router.visit(route('invoicing.recurring.index'))">
                    Cancel
                </AppButton>
            </FormActions>
        </AppCard>
    </AppLayout>
</template>
