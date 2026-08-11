<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { z } from 'zod';
import {
    Building2,
    CircleDollarSign,
    Landmark,
    Shield,
    User,
    Wallet,
} from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormValidationBanner from '@/Components/FormValidationBanner.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useToast } from '@/Composables/useToast';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type Option = { value: string; label: string };

const props = defineProps<{
    asset: null | {
        id: number;
        name: string;
        owner_name: string;
        asset_type: string;
        institution: string | null;
        liquidity: string;
        interest_rate_bps: number | null;
        notes: string | null;
        is_active: boolean;
    };
    portfolio: { id: number; base_currency: string };
    asset_types: Option[];
    liquidity_options: Option[];
}>();

const isEdit = computed(() => props.asset !== null);
const toast = useToast();
const saving = ref(false);
const { fieldErrors, setFromZod, setFromServer, clear, clearField, messages: clientErrorMessages } = useFieldErrors();

const form = ref({
    name: props.asset?.name ?? '',
    owner_name: props.asset?.owner_name ?? '',
    asset_type: props.asset?.asset_type ?? 'investment_account',
    institution: props.asset?.institution ?? '',
    liquidity: props.asset?.liquidity ?? 'accessible',
    interest_rate_percent: props.asset?.interest_rate_bps != null
        ? String(props.asset.interest_rate_bps / 100)
        : '',
    notes: props.asset?.notes ?? '',
    is_active: props.asset?.is_active ?? true,
    opening_value: '',
    opening_valued_on: new Date().toISOString().slice(0, 10),
});

const setField = <K extends keyof typeof form.value>(key: K, value: (typeof form.value)[K]) => {
    form.value[key] = value;
    clearField(String(key));
};

const liquidityHelp: Record<string, string> = {
    immediately_available: 'Cash or equivalents you can use today.',
    accessible: 'Available without long notice or penalties.',
    restricted: 'Locked, notice period, or otherwise hard to access.',
    retirement: 'Pension, RA, or other retirement-restricted capital.',
};

const assetTypeOptions = computed(() =>
    props.asset_types.map((o) => ({ label: o.label, value: o.value })),
);

const liquiditySelectOptions = computed(() =>
    props.liquidity_options.map((o) => ({ label: o.label, value: o.value })),
);

const selectedLiquidityHelp = computed(
    () => liquidityHelp[form.value.liquidity] ?? 'How quickly this capital can be used.',
);

const openingPreview = computed(() => {
    const n = Number(form.value.opening_value);
    if (!Number.isFinite(n) || form.value.opening_value === '') {
        return null;
    }
    return useFormatCurrency(n, props.portfolio.base_currency);
});

const schema = z.object({
    name: z.string().trim().min(1, 'Asset name is required'),
    owner_name: z.string().trim().min(1, 'Owner is required'),
    asset_type: z.string().min(1, 'Choose an asset type'),
    institution: z.string().optional(),
    liquidity: z.string().min(1, 'Choose a liquidity classification'),
    interest_rate_percent: z.preprocess(
        (v) => (v === '' || v === null || v === undefined ? null : Number(v)),
        z.number().min(0, 'Interest rate cannot be negative').max(1000, 'Interest rate looks too high').nullable(),
    ),
    notes: z.string().optional(),
    is_active: z.boolean(),
    opening_value: z.preprocess(
        (v) => (v === '' || v === null || v === undefined ? null : Number(v)),
        z.number().min(0, 'Opening value cannot be negative').nullable(),
    ),
    opening_valued_on: z.string().optional(),
});

const cancel = () => {
    if (isEdit.value && props.asset) {
        router.visit(route('wealth.assets.show', props.asset.id));
        return;
    }
    router.visit(route('wealth.index'));
};

const submit = () => {
    if (saving.value) return;

    const parsed = schema.safeParse({ ...form.value });
    if (!parsed.success) {
        setFromZod(parsed.error);
        return;
    }

    clear();

    const interest = parsed.data.interest_rate_percent == null
        ? null
        : Math.round(parsed.data.interest_rate_percent * 100);

    const payload: Record<string, unknown> = {
        name: parsed.data.name,
        owner_name: parsed.data.owner_name,
        asset_type: parsed.data.asset_type,
        institution: parsed.data.institution?.trim() ? parsed.data.institution.trim() : null,
        liquidity: parsed.data.liquidity,
        interest_rate_bps: interest,
        notes: parsed.data.notes?.trim() ? parsed.data.notes.trim() : null,
        is_active: parsed.data.is_active,
        portfolio_id: props.portfolio.id,
    };

    if (!isEdit.value && parsed.data.opening_value != null) {
        payload.opening_value_cents = Math.round(parsed.data.opening_value * 100);
        payload.opening_valued_on = parsed.data.opening_valued_on || new Date().toISOString().slice(0, 10);
    }

    const visitOptions = {
        onStart: () => {
            saving.value = true;
        },
        onSuccess: () => {
            toast.success(isEdit.value ? 'Asset updated.' : 'Asset created.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
            if (!Object.keys(errors).length) {
                toast.error('Could not save this asset.');
            }
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (isEdit.value && props.asset) {
        router.put(route('wealth.assets.update', props.asset.id), payload, visitOptions);
        return;
    }

    router.post(route('wealth.assets.store'), payload, visitOptions);
};
</script>

<template>
    <AppLayout
        :title="isEdit ? 'Edit asset' : 'Add asset'"
        :breadcrumbs="[
            { label: 'Wealth', href: route('wealth.index') },
            ...(isEdit && asset
                ? [{ label: asset.name, href: route('wealth.assets.show', asset.id) }, { label: 'Edit' }]
                : [{ label: 'Add asset' }]),
        ]"
    >
        <PageHeader
            :title="isEdit ? 'Edit asset' : 'Add asset'"
            :subtitle="
                isEdit
                    ? 'Update how this holding is classified. Valuations and cash flows stay on the asset page.'
                    : 'Describe the holding once — valuations and contributions are tracked separately over time.'
            "
        />

        <FormValidationBanner class="mt-5" :errors="clientErrorMessages" />

        <AppCard class="mt-5 overflow-hidden">
            <form @submit.prevent="submit">
                <div class="space-y-8 p-5 md:p-6">
                    <section class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 rounded-lg bg-slate-100 p-2 text-slate-600">
                                <Wallet class="h-4 w-4" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">Identity</h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    How this asset appears in your portfolio and reports.
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-medium text-slate-500">
                                    Asset name <span class="text-rose-600">*</span>
                                </label>
                                <AppInput
                                    :model-value="form.name"
                                    placeholder="e.g. TFSA — EasyEquities"
                                    required
                                    @update:model-value="setField('name', $event)"
                                />
                                <p v-if="fieldErrors.name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name }}</p>
                            </div>

                            <div>
                                <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-500">
                                    <User class="h-3.5 w-3.5" />
                                    Owner <span class="text-rose-600">*</span>
                                </label>
                                <AppInput
                                    :model-value="form.owner_name"
                                    placeholder="e.g. Gideon, Joint, Trust"
                                    required
                                    @update:model-value="setField('owner_name', $event)"
                                />
                                <p class="mt-1 text-xs text-slate-500">Used for owner breakdowns and contribution allowances.</p>
                                <p v-if="fieldErrors.owner_name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.owner_name }}</p>
                            </div>

                            <div>
                                <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-500">
                                    <Building2 class="h-3.5 w-3.5" />
                                    Institution / provider
                                </label>
                                <AppInput
                                    :model-value="form.institution"
                                    placeholder="e.g. Allan Gray, FNB, Self-custody"
                                    @update:model-value="setField('institution', $event)"
                                />
                            </div>
                        </div>
                    </section>

                    <section class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 rounded-lg bg-slate-100 p-2 text-slate-600">
                                <Landmark class="h-4 w-4" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">Classification</h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    Type and liquidity drive portfolio totals and accessible vs restricted wealth.
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">
                                    Asset type <span class="text-rose-600">*</span>
                                </label>
                                <AppSelect
                                    :model-value="form.asset_type"
                                    :options="assetTypeOptions"
                                    placeholder="Select type"
                                    @update:model-value="setField('asset_type', $event)"
                                />
                                <p v-if="fieldErrors.asset_type" class="mt-1 text-xs text-rose-600">{{ fieldErrors.asset_type }}</p>
                            </div>

                            <div>
                                <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-500">
                                    <Shield class="h-3.5 w-3.5" />
                                    Liquidity <span class="text-rose-600">*</span>
                                </label>
                                <AppSelect
                                    :model-value="form.liquidity"
                                    :options="liquiditySelectOptions"
                                    placeholder="Select liquidity"
                                    @update:model-value="setField('liquidity', $event)"
                                />
                                <p class="mt-1 text-xs text-slate-500">{{ selectedLiquidityHelp }}</p>
                                <p v-if="fieldErrors.liquidity" class="mt-1 text-xs text-rose-600">{{ fieldErrors.liquidity }}</p>
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Interest rate (%)</label>
                                <AppInput
                                    :model-value="form.interest_rate_percent"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    placeholder="Optional — e.g. 5.25"
                                    @update:model-value="setField('interest_rate_percent', $event)"
                                />
                                <p class="mt-1 text-xs text-slate-500">For savings or fixed-return accounts. Leave blank if not applicable.</p>
                                <p v-if="fieldErrors.interest_rate_percent" class="mt-1 text-xs text-rose-600">
                                    {{ fieldErrors.interest_rate_percent }}
                                </p>
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Portfolio currency</label>
                                <div class="flex h-10 items-center rounded-md border border-slate-200 bg-slate-50 px-3 text-sm font-medium text-slate-700">
                                    {{ portfolio.base_currency }}
                                </div>
                                <p class="mt-1 text-xs text-slate-500">Assets in this portfolio use the portfolio base currency.</p>
                            </div>
                        </div>
                    </section>

                    <section
                        v-if="!isEdit"
                        class="space-y-4 rounded-xl border border-slate-200 bg-slate-50/80 p-4 md:p-5"
                    >
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 rounded-lg bg-white p-2 text-slate-600 shadow-sm">
                                <CircleDollarSign class="h-4 w-4" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900">Opening valuation</h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    Optional starting balance. You can add or update valuations any time from the asset page.
                                </p>
                            </div>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">
                                    Value ({{ portfolio.base_currency }})
                                </label>
                                <AppInput
                                    :model-value="form.opening_value"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    placeholder="0.00"
                                    @update:model-value="setField('opening_value', $event)"
                                />
                                <p v-if="openingPreview" class="mt-1 text-xs text-slate-600">
                                    Recorded as <span class="font-medium tabular-nums">{{ openingPreview }}</span>
                                </p>
                                <p v-if="fieldErrors.opening_value" class="mt-1 text-xs text-rose-600">
                                    {{ fieldErrors.opening_value }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Valued on</label>
                                <AppInput
                                    :model-value="form.opening_valued_on"
                                    type="date"
                                    @update:model-value="setField('opening_valued_on', $event)"
                                />
                            </div>
                        </div>
                    </section>

                    <section class="space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900">Notes &amp; status</h3>
                            <p class="mt-1 text-sm text-slate-500">Anything that helps you recognise this holding later.</p>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div v-if="isEdit">
                                <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                                <AppSelect
                                    :model-value="form.is_active ? 'active' : 'inactive'"
                                    :options="[
                                        { label: 'Active', value: 'active' },
                                        { label: 'Inactive', value: 'inactive' },
                                    ]"
                                    @update:model-value="setField('is_active', $event === 'active')"
                                />
                                <p class="mt-1 text-xs text-slate-500">
                                    Inactive assets stay on record but are excluded from portfolio totals.
                                </p>
                            </div>
                            <div :class="isEdit ? 'md:col-span-1' : 'md:col-span-2'">
                                <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                                <textarea
                                    :value="form.notes"
                                    rows="4"
                                    class="min-h-28 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                                    placeholder="Account number hints, strategy notes, or reminders — not shown on the overview table."
                                    @input="setField('notes', ($event.target as HTMLTextAreaElement).value)"
                                />
                            </div>
                        </div>
                    </section>
                </div>

                <FormActions bordered>
                    <AppButton type="submit" variant="primary" :loading="saving" :disabled="saving">
                        {{ saving ? 'Saving…' : isEdit ? 'Update asset' : 'Create asset' }}
                    </AppButton>
                    <AppButton type="button" variant="secondary" :disabled="saving" @click="cancel">
                        Cancel
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </AppLayout>
</template>
