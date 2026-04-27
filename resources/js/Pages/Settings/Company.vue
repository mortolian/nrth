<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionMessage from '@/Components/ActionMessage.vue';
import { Building2, ImagePlus, Trash2 } from 'lucide-vue-next';

type Settings = Record<string, unknown>;

const props = defineProps<{
    team: { id: number; name: string };
    settings: Settings;
    logo_url: string | null;
    invoice_next_sequence: number;
    tax_rates: Array<{
        id: number;
        name: string;
        code: string;
        rate: number;
        rate_percent: number;
        is_default: boolean;
        is_exempt: boolean;
        is_active: boolean;
    }>;
    industries: Array<{ value: string; label: string }>;
    financial_year_months: Array<{ value: number; label: string }>;
    vat_period_types: Array<{ value: string; label: string }>;
    bank_account_types: Array<{ value: string; label: string }>;
}>();

type CompanyTab = 'profile' | 'contact' | 'invoice' | 'tax' | 'banking';
const allowedTabs: CompanyTab[] = ['profile', 'contact', 'invoice', 'tax', 'banking'];
const initialTab = new URLSearchParams(window.location.search).get('tab');
const tab = ref<CompanyTab>(allowedTabs.includes(initialTab as CompanyTab) ? (initialTab as CompanyTab) : 'profile');

const form = useForm({
    name: props.team.name,
    trading_name: String(props.settings.trading_name ?? ''),
    registration_number: String(props.settings.registration_number ?? ''),
    vat_number: String(props.settings.vat_number ?? ''),
    tax_reference: String(props.settings.tax_reference ?? ''),
    industry: String(props.settings.industry ?? ''),
    financial_year_end_month: Number(props.settings.financial_year_end_month ?? 2),
    physical_street: String(props.settings.physical_street ?? ''),
    physical_city: String(props.settings.physical_city ?? ''),
    physical_province: String(props.settings.physical_province ?? ''),
    physical_postal_code: String(props.settings.physical_postal_code ?? ''),
    physical_country: String(props.settings.physical_country ?? 'South Africa'),
    postal_same_as_physical: Boolean(props.settings.postal_same_as_physical ?? true),
    postal_street: String(props.settings.postal_street ?? ''),
    postal_city: String(props.settings.postal_city ?? ''),
    postal_province: String(props.settings.postal_province ?? ''),
    postal_postal_code: String(props.settings.postal_postal_code ?? ''),
    postal_country: String(props.settings.postal_country ?? ''),
    company_email: String(props.settings.company_email ?? ''),
    company_phone: String(props.settings.company_phone ?? ''),
    company_website: String(props.settings.company_website ?? ''),
    invoice_default_payment_terms_days: Number(props.settings.invoice_default_payment_terms_days ?? 30),
    invoice_prefix: String(props.settings.invoice_prefix ?? 'INV'),
    invoice_next_sequence: props.invoice_next_sequence,
    invoice_default_notes: String(props.settings.invoice_default_notes ?? ''),
    invoice_default_footer: String(props.settings.invoice_default_footer ?? ''),
    invoice_email_subject_template: String(props.settings.invoice_email_subject_template ?? ''),
    invoice_email_body_template: String(props.settings.invoice_email_body_template ?? ''),
    vat_registered: Boolean(props.settings.vat_registered ?? true),
    vat_period_type: String(props.settings.vat_period_type ?? 'bi_monthly'),
    default_tax_rate_id: props.settings.default_tax_rate_id != null ? String(props.settings.default_tax_rate_id) : '',
    bank_name: String(props.settings.bank_name ?? ''),
    bank_account_holder: String(props.settings.bank_account_holder ?? ''),
    bank_account_number: String(props.settings.bank_account_number ?? ''),
    bank_branch_code: String(props.settings.bank_branch_code ?? ''),
    bank_account_type: String(props.settings.bank_account_type ?? 'current'),
});

const logoFile = ref<File | null>(null);
const logoPreview = ref<string | null>(null);
const removeLogo = ref(false);

const liveInvoicePreview = computed(() => {
    const y = new Date().getFullYear();
    const raw = (form.invoice_prefix || 'INV').trim().replace(/-+$/, '');
    const base = raw || 'INV';
    const seq = Math.max(1, Number(form.invoice_next_sequence) || 1);
    return `${base}-${y}-${String(seq).padStart(4, '0')}`;
});

const displayLogo = computed(() => {
    if (logoPreview.value) {
        return logoPreview.value;
    }
    if (removeLogo.value) {
        return null;
    }
    return props.logo_url;
});

watch(
    () => form.postal_same_as_physical,
    (same) => {
        if (same) {
            form.postal_street = form.physical_street;
            form.postal_city = form.physical_city;
            form.postal_province = form.physical_province;
            form.postal_postal_code = form.physical_postal_code;
            form.postal_country = form.physical_country;
        }
    },
);

watch(
    () => [
        form.physical_street,
        form.physical_city,
        form.physical_province,
        form.physical_postal_code,
        form.physical_country,
    ],
    () => {
        if (form.postal_same_as_physical) {
            form.postal_street = form.physical_street;
            form.postal_city = form.physical_city;
            form.postal_province = form.physical_province;
            form.postal_postal_code = form.physical_postal_code;
            form.postal_country = form.physical_country;
        }
    },
);

const onLogo = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    logoFile.value = file;
    removeLogo.value = false;
    if (logoPreview.value) {
        URL.revokeObjectURL(logoPreview.value);
    }
    logoPreview.value = file ? URL.createObjectURL(file) : null;
};

const clearLogo = () => {
    logoFile.value = null;
    removeLogo.value = true;
    if (logoPreview.value) {
        URL.revokeObjectURL(logoPreview.value);
    }
    logoPreview.value = null;
};

const tabs = [
    { id: 'profile' as const, label: 'Company profile' },
    { id: 'contact' as const, label: 'Contact' },
    { id: 'invoice' as const, label: 'Invoice defaults' },
    { id: 'tax' as const, label: 'VAT' },
    { id: 'banking' as const, label: 'Banking' },
];

const activeTaxRates = computed(() => props.tax_rates.filter((rate) => rate.is_active));
const validTaxRateIds = computed(() => new Set(activeTaxRates.value.map((rate) => String(rate.id))));
const submit = () => {
    const selectedTaxRateId = form.default_tax_rate_id ? String(form.default_tax_rate_id) : '';

    const payload: Record<string, unknown> = {
        name: form.name,
        trading_name: form.trading_name,
        registration_number: form.registration_number,
        vat_number: form.vat_number,
        tax_reference: form.tax_reference,
        industry: form.industry,
        financial_year_end_month: form.financial_year_end_month,
        physical_street: form.physical_street,
        physical_city: form.physical_city,
        physical_province: form.physical_province,
        physical_postal_code: form.physical_postal_code,
        physical_country: form.physical_country,
        postal_same_as_physical: form.postal_same_as_physical,
        postal_street: form.postal_street,
        postal_city: form.postal_city,
        postal_province: form.postal_province,
        postal_postal_code: form.postal_postal_code,
        postal_country: form.postal_country,
        company_email: form.company_email,
        company_phone: form.company_phone,
        company_website: form.company_website,
        invoice_default_payment_terms_days: form.invoice_default_payment_terms_days,
        invoice_prefix: form.invoice_prefix,
        invoice_next_sequence: form.invoice_next_sequence,
        invoice_default_notes: form.invoice_default_notes,
        invoice_default_footer: form.invoice_default_footer,
        invoice_email_subject_template: form.invoice_email_subject_template,
        invoice_email_body_template: form.invoice_email_body_template,
        vat_registered: form.vat_registered,
        vat_period_type: form.vat_period_type,
        default_tax_rate_id: validTaxRateIds.value.has(selectedTaxRateId) ? selectedTaxRateId : '',
        bank_name: form.bank_name,
        bank_account_holder: form.bank_account_holder,
        bank_account_number: form.bank_account_number,
        bank_branch_code: form.bank_branch_code,
        bank_account_type: form.bank_account_type,
        remove_logo: removeLogo.value ? 1 : 0,
    };

    if (logoFile.value) {
        payload.logo = logoFile.value;
    }

    payload.tab = tab.value;

    form.transform(() => payload).post(route('settings.company.update', { tab: tab.value }), {
        preserveState: true,
        preserveScroll: true,
        forceFormData: Boolean(logoFile.value),
        onError: (errors) => {
            if (errors.vat_number && tab.value !== 'profile' && tab.value !== 'tax') {
                tab.value = 'tax';
            }
        },
    });
};
</script>

<template>
    <AppLayout
        title="Company settings"
        :breadcrumbs="[
            { label: 'Settings', href: route('profile.show') },
            { label: 'Company' },
        ]"
    >
        <PageHeader title="Company settings" subtitle="Profile, invoicing, tax, and banking details for your business" />

        <div class="mt-5 flex flex-wrap gap-2 border-b border-slate-200 pb-3">
            <button
                v-for="t in tabs"
                :key="t.id"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                :class="tab === t.id ? 'bg-brand-500 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                @click="tab = t.id"
            >
                {{ t.label }}
            </button>
        </div>

        <div class="mt-5 space-y-6">
            <div v-if="Object.keys(form.errors).length" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <p class="font-medium">Could not save company settings.</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
                </ul>
            </div>

            <AppCard v-show="tab === 'profile'">
                <h3 class="text-base font-semibold text-slate-900">Company profile</h3>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Company name</label>
                        <AppInput v-model="form.name" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Trading name (if different)</label>
                        <AppInput v-model="form.trading_name" placeholder="Optional" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Registration number</label>
                        <AppInput v-model="form.registration_number" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">VAT number</label>
                        <AppInput v-model="form.vat_number" placeholder="4XXXXXXXXX" maxlength="10" />
                        <p class="mt-1 text-xs text-slate-500">South African VAT numbers are 10 digits starting with 4.</p>
                        <p v-if="form.errors.vat_number" class="mt-1 text-xs text-rose-600">{{ form.errors.vat_number }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Tax reference (SARS)</label>
                        <AppInput v-model="form.tax_reference" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Industry</label>
                        <AppSelect
                            :model-value="form.industry || ''"
                            :options="industries.map((i) => ({ label: i.label, value: i.value }))"
                            placeholder="Select industry"
                            @update:model-value="form.industry = $event"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Financial year end</label>
                        <AppSelect
                            :model-value="String(form.financial_year_end_month)"
                            :options="financial_year_months.map((m) => ({ label: m.label, value: String(m.value) }))"
                            @update:model-value="form.financial_year_end_month = Number($event)"
                        />
                        <p class="mt-1 text-xs text-slate-500">South Africa commonly uses February.</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Logo</label>
                        <p class="mb-2 text-xs text-slate-500">Shown on invoices and reports (PNG or JPG, max 4&nbsp;MB).</p>
                        <div class="flex flex-wrap items-center gap-4">
                            <div
                                class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50"
                            >
                                <img v-if="displayLogo" :src="displayLogo" alt="Logo" class="max-h-full max-w-full object-contain">
                                <Building2 v-else class="h-8 w-8 text-slate-300" />
                            </div>
                            <div class="flex gap-2">
                                <label class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                                    <ImagePlus class="h-4 w-4" />
                                    Upload
                                    <input type="file" accept="image/*" class="hidden" @change="onLogo">
                                </label>
                                <button
                                    v-if="displayLogo"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-md border border-rose-200 px-3 py-2 text-sm text-rose-700 hover:bg-rose-50"
                                    @click="clearLogo"
                                >
                                    <Trash2 class="h-4 w-4" /> Remove
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </AppCard>

            <AppCard v-show="tab === 'contact'">
                <h3 class="text-base font-semibold text-slate-900">Contact details</h3>
                <p class="mt-1 text-sm text-slate-500">Physical address</p>
                <div class="mt-3 grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Street</label>
                        <AppInput v-model="form.physical_street" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">City</label>
                        <AppInput v-model="form.physical_city" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Province</label>
                        <AppInput v-model="form.physical_province" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Postal code</label>
                        <AppInput v-model="form.physical_postal_code" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Country</label>
                        <AppInput v-model="form.physical_country" />
                    </div>
                </div>

                <label class="mt-6 flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="form.postal_same_as_physical" type="checkbox" class="rounded border-slate-300">
                    Postal address same as physical
                </label>

                <template v-if="!form.postal_same_as_physical">
                    <p class="mt-4 text-sm text-slate-500">Postal address</p>
                    <div class="mt-3 grid gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500">Street</label>
                            <AppInput v-model="form.postal_street" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">City</label>
                            <AppInput v-model="form.postal_city" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Province</label>
                            <AppInput v-model="form.postal_province" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Postal code</label>
                            <AppInput v-model="form.postal_postal_code" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Country</label>
                            <AppInput v-model="form.postal_country" />
                        </div>
                    </div>
                </template>

                <p class="mt-6 text-sm font-medium text-slate-900">General contact</p>
                <div class="mt-3 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Email</label>
                        <AppInput v-model="form.company_email" type="email" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Phone</label>
                        <AppInput v-model="form.company_phone" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Website</label>
                        <AppInput v-model="form.company_website" placeholder="https://" />
                    </div>
                </div>
            </AppCard>

            <AppCard v-show="tab === 'invoice'">
                <h3 class="text-base font-semibold text-slate-900">Invoice defaults</h3>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Default payment terms (days)</label>
                        <AppInput v-model="form.invoice_default_payment_terms_days" type="number" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Invoice prefix</label>
                        <AppInput v-model="form.invoice_prefix" placeholder="INV" />
                        <p class="mt-1 text-xs text-slate-500">Numbers format as {{ form.invoice_prefix || 'INV' }}-YEAR-####</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Next sequence number (this year)</label>
                        <AppInput v-model="form.invoice_next_sequence" type="number" min="1" />
                        <p class="mt-1 text-xs text-slate-500">Preview: {{ liveInvoicePreview }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Default notes (new invoices)</label>
                        <textarea
                            v-model="form.invoice_default_notes"
                            rows="3"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                        />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Default footer (banking details, etc.)</label>
                        <textarea
                            v-model="form.invoice_default_footer"
                            rows="4"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                        />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Email subject template</label>
                        <AppInput v-model="form.invoice_email_subject_template" />
                        <p v-pre class="mt-1 text-xs text-slate-500">Placeholders: {{number}}, {{company}}, {{client_name}}</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Email body template</label>
                        <textarea
                            v-model="form.invoice_email_body_template"
                            rows="5"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                        />
                    </div>
                </div>
            </AppCard>

            <AppCard v-show="tab === 'tax'">
                <h3 class="text-base font-semibold text-slate-900">VAT settings</h3>
                <label class="mt-4 flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="form.vat_registered" type="checkbox" class="rounded border-slate-300">
                    VAT registered
                </label>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">VAT number</label>
                        <AppInput v-model="form.vat_number" :disabled="!form.vat_registered" placeholder="4XXXXXXXXX" maxlength="10" />
                        <p v-if="form.errors.vat_number" class="mt-1 text-xs text-rose-600">{{ form.errors.vat_number }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">VAT period type</label>
                        <AppSelect
                            :model-value="form.vat_period_type"
                            :options="vat_period_types.map((v) => ({ label: v.label, value: v.value }))"
                            :disabled="!form.vat_registered"
                            @update:model-value="form.vat_period_type = $event"
                        />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Default VAT rate</label>
                        <AppSelect
                            :model-value="form.default_tax_rate_id"
                            :options="[
                                { label: '— None —', value: '' },
                                ...activeTaxRates.map((r) => ({ label: `${r.name} (${(r.rate * 100).toFixed(0)}%)`, value: String(r.id) })),
                            ]"
                            :disabled="!form.vat_registered"
                            @update:model-value="form.default_tax_rate_id = $event"
                        />
                    </div>
                </div>
            </AppCard>

            <AppCard v-show="tab === 'banking'">
                <h3 class="text-base font-semibold text-slate-900">Banking details (invoices)</h3>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Bank name</label>
                        <AppInput v-model="form.bank_name" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Account holder</label>
                        <AppInput v-model="form.bank_account_holder" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Account number</label>
                        <AppInput v-model="form.bank_account_number" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Branch code</label>
                        <AppInput v-model="form.bank_branch_code" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Account type</label>
                        <AppSelect
                            :model-value="form.bank_account_type"
                            :options="bank_account_types.map((b) => ({ label: b.label, value: b.value }))"
                            @update:model-value="form.bank_account_type = $event"
                        />
                    </div>
                </div>
            </AppCard>
        </div>

        <div class="mt-8 flex justify-end border-t border-slate-200 pt-6">
            <ActionMessage :on="form.recentlySuccessful" class="me-3">
                Saved.
            </ActionMessage>
            <button
                type="button"
                class="inline-flex items-center justify-center rounded-md bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-400 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="form.processing"
                @click="submit"
            >
                {{ form.processing ? 'Saving…' : 'Save changes' }}
            </button>
        </div>
    </AppLayout>
</template>
