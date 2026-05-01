<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ActionMessage from '@/Components/ActionMessage.vue';
import AppButton from '@/Components/AppButton.vue';
import AppPhoneInput from '@/Components/AppPhoneInput.vue';
import { Building2, ImagePlus, Plus, Trash2 } from 'lucide-vue-next';

type Settings = Record<string, unknown>;

type BankAccountRow = {
    title: string;
    bank_name: string;
    bank_account_holder: string;
    bank_account_number: string;
    swift_code: string;
    bic: string;
    iban: string;
    routing_sort_code: string;
    bank_branch_code: string;
    bank_account_type: string;
    show_on_invoice: boolean;
};

const emptyBankRow = (): BankAccountRow => ({
    title: '',
    bank_name: '',
    bank_account_holder: '',
    bank_account_number: '',
    swift_code: '',
    bic: '',
    iban: '',
    routing_sort_code: '',
    bank_branch_code: '',
    bank_account_type: 'current',
    show_on_invoice: true,
});

const props = defineProps<{
    team: { id: number; name: string };
    settings: Settings;
    bank_accounts: BankAccountRow[];
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

type CompanyTab = 'profile' | 'contact' | 'invoice' | 'estimate' | 'tax' | 'banking' | 'payment_pages';
const page = usePage();
const currencyOptions = computed(
    () => (page.props.currencyOptions as Array<{ value: string; label: string }>) ?? [],
);

const allowedTabs: CompanyTab[] = ['profile', 'contact', 'invoice', 'estimate', 'tax', 'banking', 'payment_pages'];
const initialTab = new URLSearchParams(window.location.search).get('tab');
const tab = ref<CompanyTab>(allowedTabs.includes(initialTab as CompanyTab) ? (initialTab as CompanyTab) : 'profile');

watch(tab, (next) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', next);
    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
});

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
    invoice_default_currency: String(props.settings.invoice_default_currency ?? 'ZAR'),
    invoice_prefix: String(props.settings.invoice_prefix ?? 'INV'),
    invoice_number_include_month: Boolean(props.settings.invoice_number_include_month ?? false),
    invoice_number_use_random_suffix: Boolean(props.settings.invoice_number_use_random_suffix ?? false),
    estimate_prefix: String(props.settings.estimate_prefix ?? 'EST'),
    estimate_number_include_month: Boolean(props.settings.estimate_number_include_month ?? false),
    estimate_number_use_random_suffix: Boolean(props.settings.estimate_number_use_random_suffix ?? false),
    estimate_default_notes: String(props.settings.estimate_default_notes ?? ''),
    estimate_default_terms: String(props.settings.estimate_default_terms ?? ''),
    estimate_show_street_address: Boolean(props.settings.estimate_show_street_address ?? true),
    invoice_show_street_address: Boolean(props.settings.invoice_show_street_address ?? true),
    invoice_next_sequence: props.invoice_next_sequence,
    invoice_default_notes: String(props.settings.invoice_default_notes ?? ''),
    invoice_default_footer: String(props.settings.invoice_default_footer ?? ''),
    invoice_email_subject_template: String(props.settings.invoice_email_subject_template ?? ''),
    invoice_email_body_template: String(props.settings.invoice_email_body_template ?? ''),
    vat_registered: Boolean(props.settings.vat_registered ?? true),
    vat_period_type: String(props.settings.vat_period_type ?? 'bi_monthly'),
    default_tax_rate_id: props.settings.default_tax_rate_id != null ? String(props.settings.default_tax_rate_id) : '',
    payment_pages_enabled: Boolean(props.settings.payment_pages_enabled ?? true),
    payment_gateways: {
        payfast: {
            enabled: Boolean((props.settings.payment_gateways as any)?.payfast?.enabled ?? false),
            merchant_id: String((props.settings.payment_gateways as any)?.payfast?.merchant_id ?? ''),
            merchant_key: String((props.settings.payment_gateways as any)?.payfast?.merchant_key ?? ''),
            passphrase: String((props.settings.payment_gateways as any)?.payfast?.passphrase ?? ''),
        },
        stripe: {
            enabled: Boolean((props.settings.payment_gateways as any)?.stripe?.enabled ?? false),
            publishable_key: String((props.settings.payment_gateways as any)?.stripe?.publishable_key ?? ''),
            secret_key: String((props.settings.payment_gateways as any)?.stripe?.secret_key ?? ''),
            webhook_secret: String((props.settings.payment_gateways as any)?.stripe?.webhook_secret ?? ''),
        },
        paypal: {
            enabled: Boolean((props.settings.payment_gateways as any)?.paypal?.enabled ?? false),
            client_id: String((props.settings.payment_gateways as any)?.paypal?.client_id ?? ''),
            client_secret: String((props.settings.payment_gateways as any)?.paypal?.client_secret ?? ''),
            environment: String((props.settings.payment_gateways as any)?.paypal?.environment ?? 'sandbox'),
        },
        netcash: {
            enabled: Boolean((props.settings.payment_gateways as any)?.netcash?.enabled ?? false),
            account_id: String((props.settings.payment_gateways as any)?.netcash?.account_id ?? ''),
            service_key: String((props.settings.payment_gateways as any)?.netcash?.service_key ?? ''),
        },
        snapscan: {
            enabled: Boolean((props.settings.payment_gateways as any)?.snapscan?.enabled ?? false),
            merchant_id: String((props.settings.payment_gateways as any)?.snapscan?.merchant_id ?? ''),
            api_key: String((props.settings.payment_gateways as any)?.snapscan?.api_key ?? ''),
        },
        zapper: {
            enabled: Boolean((props.settings.payment_gateways as any)?.zapper?.enabled ?? false),
            merchant_id: String((props.settings.payment_gateways as any)?.zapper?.merchant_id ?? ''),
            api_key: String((props.settings.payment_gateways as any)?.zapper?.api_key ?? ''),
        },
    },
    bank_accounts:
        props.bank_accounts.length > 0
            ? props.bank_accounts.map((r) => ({
                  title: String(r.title ?? ''),
                  bank_name: String(r.bank_name ?? ''),
                  bank_account_holder: String(r.bank_account_holder ?? ''),
                  bank_account_number: String(r.bank_account_number ?? ''),
                  swift_code: String(r.swift_code ?? ''),
                  bic: String(r.bic ?? ''),
                  iban: String(r.iban ?? ''),
                  routing_sort_code: String(r.routing_sort_code ?? ''),
                  bank_branch_code: String(r.bank_branch_code ?? ''),
                  bank_account_type: String(r.bank_account_type ?? 'current'),
                  show_on_invoice: Boolean(r.show_on_invoice),
              }))
            : [emptyBankRow()],
});

const logoFile = ref<File | null>(null);
const logoPreview = ref<string | null>(null);
const removeLogo = ref(false);

const liveInvoicePreview = computed(() => {
    const now = new Date();
    const y = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const raw = (form.invoice_prefix || 'INV').trim().replace(/-+$/, '');
    const base = raw || 'INV';
    const seq = Math.max(1, Number(form.invoice_next_sequence) || 1);
    const suffix = form.invoice_number_use_random_suffix ? 'sdfg' : String(seq).padStart(4, '0');
    return form.invoice_number_include_month
        ? `${base}-${y}-${month}-${suffix}`
        : `${base}-${y}-${suffix}`;
});

const liveEstimatePreview = computed(() => {
    const now = new Date();
    const y = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const raw = (form.estimate_prefix || 'EST').trim().replace(/-+$/, '');
    const base = raw || 'EST';
    const seq = Math.max(1, Number(form.invoice_next_sequence) || 1);
    const suffix = form.estimate_number_use_random_suffix ? 'sdfg' : String(seq).padStart(4, '0');
    return form.estimate_number_include_month
        ? `${base}-${y}-${month}-${suffix}`
        : `${base}-${y}-${suffix}`;
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
    { id: 'invoice' as const, label: 'Invoices' },
    { id: 'estimate' as const, label: 'Estimates' },
    { id: 'tax' as const, label: 'VAT' },
    { id: 'banking' as const, label: 'Banking' },
    { id: 'payment_pages' as const, label: 'Online payments' },
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
        invoice_default_currency: form.invoice_default_currency,
        invoice_prefix: form.invoice_prefix,
        invoice_number_include_month: form.invoice_number_include_month,
        invoice_number_use_random_suffix: form.invoice_number_use_random_suffix,
        estimate_prefix: form.estimate_prefix,
        estimate_number_include_month: form.estimate_number_include_month,
        estimate_number_use_random_suffix: form.estimate_number_use_random_suffix,
        estimate_default_notes: form.estimate_default_notes,
        estimate_default_terms: form.estimate_default_terms,
        estimate_show_street_address: form.estimate_show_street_address,
        invoice_show_street_address: form.invoice_show_street_address,
        invoice_next_sequence: form.invoice_next_sequence,
        invoice_default_notes: form.invoice_default_notes,
        invoice_default_footer: form.invoice_default_footer,
        invoice_email_subject_template: form.invoice_email_subject_template,
        invoice_email_body_template: form.invoice_email_body_template,
        vat_registered: form.vat_registered,
        vat_period_type: form.vat_period_type,
        default_tax_rate_id: validTaxRateIds.value.has(selectedTaxRateId) ? selectedTaxRateId : '',
        payment_pages_enabled: form.payment_pages_enabled,
        payment_gateways: {
            payfast: {
                enabled: form.payment_gateways.payfast.enabled,
                merchant_id: form.payment_gateways.payfast.merchant_id,
                merchant_key: form.payment_gateways.payfast.merchant_key,
                passphrase: form.payment_gateways.payfast.passphrase,
            },
            stripe: {
                enabled: form.payment_gateways.stripe.enabled,
                publishable_key: form.payment_gateways.stripe.publishable_key,
                secret_key: form.payment_gateways.stripe.secret_key,
                webhook_secret: form.payment_gateways.stripe.webhook_secret,
            },
            paypal: {
                enabled: form.payment_gateways.paypal.enabled,
                client_id: form.payment_gateways.paypal.client_id,
                client_secret: form.payment_gateways.paypal.client_secret,
                environment: form.payment_gateways.paypal.environment,
            },
            netcash: {
                enabled: form.payment_gateways.netcash.enabled,
                account_id: form.payment_gateways.netcash.account_id,
                service_key: form.payment_gateways.netcash.service_key,
            },
            snapscan: {
                enabled: form.payment_gateways.snapscan.enabled,
                merchant_id: form.payment_gateways.snapscan.merchant_id,
                api_key: form.payment_gateways.snapscan.api_key,
            },
            zapper: {
                enabled: form.payment_gateways.zapper.enabled,
                merchant_id: form.payment_gateways.zapper.merchant_id,
                api_key: form.payment_gateways.zapper.api_key,
            },
        },
        bank_accounts: form.bank_accounts.map((r) => ({
            title: r.title,
            bank_name: r.bank_name,
            bank_account_holder: r.bank_account_holder,
            bank_account_number: r.bank_account_number,
            swift_code: r.swift_code,
            bic: r.bic,
            iban: r.iban,
            routing_sort_code: r.routing_sort_code,
            bank_branch_code: r.bank_branch_code,
            bank_account_type: r.bank_account_type,
            show_on_invoice: r.show_on_invoice,
        })),
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

const addBankAccount = () => {
    form.bank_accounts.push(emptyBankRow());
};

const removeBankAccount = (index: number) => {
    if (form.bank_accounts.length <= 1) {
        return;
    }
    form.bank_accounts.splice(index, 1);
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
        <PageHeader
            title="Company settings"
            subtitle="Profile, invoicing, tax, banking, and online payments (card checkout). Use the Online payments tab for Stripe, PayFast, and other gateways."
        />

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
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-slate-500">
                    Legal and trading identity, tax references, and your logo. These feed invoices, estimates, and reports.
                </p>

                <div class="mt-6 space-y-5">
                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Names &amp; registration</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Registered name as on official documents; trading name if you market under a different brand.</p>
                        <div class="mt-4 space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Company name</label>
                                <AppInput v-model="form.name" />
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-500">Trading name (optional)</label>
                                    <AppInput v-model="form.trading_name" placeholder="If different from company name" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-500">Registration number</label>
                                    <AppInput v-model="form.registration_number" />
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Tax</h4>
                        <p class="mt-0.5 text-xs text-slate-500">VAT and SARS references used on tax invoices and compliance.</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
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
                        </div>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Business classification</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Industry and financial year for reporting context.</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
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
                        </div>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Logo</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Shown on invoices, estimates, and other customer-facing PDFs (PNG or JPG, max 4&nbsp;MB).</p>
                        <div class="mt-4 flex flex-wrap items-center gap-4">
                            <div
                                class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                            >
                                <img v-if="displayLogo" :src="displayLogo" alt="Logo preview" class="max-h-full max-w-full object-contain">
                                <Building2 v-else class="h-9 w-9 text-slate-300" />
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                                    <ImagePlus class="h-4 w-4" />
                                    Upload
                                    <input type="file" accept="image/*" class="hidden" @change="onLogo">
                                </label>
                                <button
                                    v-if="displayLogo"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-medium text-rose-700 shadow-sm hover:bg-rose-50"
                                    @click="clearLogo"
                                >
                                    <Trash2 class="h-4 w-4" /> Remove
                                </button>
                            </div>
                        </div>
                    </section>
                </div>
            </AppCard>

            <AppCard v-show="tab === 'contact'">
                <h3 class="text-base font-semibold text-slate-900">Contact</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-slate-500">
                    Where your business is based, how postal mail reaches you, and the email, phone, and website shown to clients on documents.
                </p>

                <div class="mt-6 space-y-5">
                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Physical address</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Principal place of business; used on invoices and estimates when you include address lines.</p>
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
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
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Postal address</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Optional separate mailing address when it differs from your physical location.</p>
                        <div class="mt-4 rounded-lg border border-slate-200/90 bg-white px-3 py-3">
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-800">
                                <input
                                    v-model="form.postal_same_as_physical"
                                    type="checkbox"
                                    class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                >
                                Postal address is the same as physical
                            </label>
                        </div>
                        <template v-if="!form.postal_same_as_physical">
                            <div class="mt-4 grid gap-4 md:grid-cols-2">
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
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Public contact details</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Shown on PDFs and in the “from” block when you email invoices.</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Email</label>
                                <AppInput v-model="form.company_email" type="email" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Phone</label>
                                <AppPhoneInput v-model="form.company_phone" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-xs font-medium text-slate-500">Website</label>
                                <AppInput v-model="form.company_website" placeholder="https://" />
                            </div>
                        </div>
                    </section>
                </div>
            </AppCard>

            <AppCard v-show="tab === 'invoice'">
                <h3 class="text-base font-semibold text-slate-900">Invoices</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-slate-500">
                    Defaults for new invoices, how numbers are generated, PDF layout, and the email templates used when you send from the app.
                </p>

                <div class="mt-6 space-y-5">
                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">New invoice defaults</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Payment terms and currency apply until you change them on the invoice or the client has a set currency.</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Default payment terms (days)</label>
                                <AppInput v-model="form.invoice_default_payment_terms_days" type="number" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Default invoice &amp; estimate currency</label>
                                <AppSelect
                                    :model-value="form.invoice_default_currency"
                                    :options="currencyOptions"
                                    @update:model-value="form.invoice_default_currency = $event"
                                />
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Invoice numbers</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Prefix and sequence; optional month segment or random suffix instead of counting.</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Prefix</label>
                                <AppInput v-model="form.invoice_prefix" placeholder="INV" />
                                <p class="mt-1.5 rounded-md bg-white/80 px-2 py-1.5 font-mono text-xs text-slate-600 ring-1 ring-slate-200/80">
                                    Preview: {{ liveInvoicePreview }}
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Next sequence (this year)</label>
                                <AppInput v-model="form.invoice_next_sequence" type="number" min="1" :disabled="form.invoice_number_use_random_suffix" />
                                <p class="mt-1 text-xs text-slate-500">Ignored when using a random suffix.</p>
                            </div>
                            <div class="sm:col-span-2">
                                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">Format options</p>
                                <div class="space-y-2.5 rounded-lg border border-slate-200/90 bg-white px-3 py-3">
                                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-800">
                                        <input v-model="form.invoice_number_include_month" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        Include month in the number
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-800">
                                        <input v-model="form.invoice_number_use_random_suffix" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        Use random 4-character suffix instead of sequence
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">PDF &amp; letterhead</h4>
                        <p class="mt-0.5 text-xs text-slate-500">What appears on invoice PDFs generated from your company profile.</p>
                        <div class="mt-4">
                            <label class="flex cursor-pointer items-start gap-2.5 text-sm text-slate-800">
                                <input v-model="form.invoice_show_street_address" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                <span>
                                    Show street address on invoice PDFs
                                    <span class="mt-1 block text-xs font-normal leading-relaxed text-slate-500">
                                        If off, only city, province, postal code and country show under your company name (email, phone and website stay as configured).
                                    </span>
                                </span>
                            </label>
                        </div>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Default document text</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Pre-filled on new invoices; you can edit per invoice.</p>
                        <div class="mt-4 space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Default notes</label>
                                <textarea
                                    v-model="form.invoice_default_notes"
                                    rows="3"
                                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Default footer (e.g. banking)</label>
                                <textarea
                                    v-model="form.invoice_default_footer"
                                    rows="4"
                                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                                />
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Send invoice email</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Templates when emailing an invoice from the app.</p>
                        <div class="mt-4 space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Subject</label>
                                <AppInput v-model="form.invoice_email_subject_template" />
                                <p v-pre class="mt-1 text-xs text-slate-500">Placeholders: {{number}}, {{company}}, {{client_name}}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Body</label>
                                <textarea
                                    v-model="form.invoice_email_body_template"
                                    rows="5"
                                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                                />
                            </div>
                        </div>
                    </section>
                </div>
            </AppCard>

            <AppCard v-show="tab === 'estimate'">
                <h3 class="text-base font-semibold text-slate-900">Estimates</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-slate-500">
                    Numbering and PDF options for quotes; default notes and terms on new estimates.
                </p>

                <div class="mt-6 space-y-5">
                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Estimate numbers</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Prefix and optional month or random suffix.</p>
                        <div class="mt-4 space-y-4">
                            <div class="max-w-md">
                                <label class="mb-1 block text-xs font-medium text-slate-500">Prefix</label>
                                <AppInput v-model="form.estimate_prefix" placeholder="EST" />
                                <p class="mt-1.5 rounded-md bg-white/80 px-2 py-1.5 font-mono text-xs text-slate-600 ring-1 ring-slate-200/80">
                                    Preview: {{ liveEstimatePreview }}
                                </p>
                            </div>
                            <div>
                                <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">Format options</p>
                                <div class="max-w-xl space-y-2.5 rounded-lg border border-slate-200/90 bg-white px-3 py-3">
                                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-800">
                                        <input v-model="form.estimate_number_include_month" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        Include month in the number
                                    </label>
                                    <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-800">
                                        <input v-model="form.estimate_number_use_random_suffix" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        Use random 4-character suffix instead of sequence
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">PDF &amp; letterhead</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Company block on estimate PDFs.</p>
                        <div class="mt-4">
                            <label class="flex cursor-pointer items-start gap-2.5 text-sm text-slate-800">
                                <input v-model="form.estimate_show_street_address" type="checkbox" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                <span>
                                    Show street address on estimate PDFs
                                    <span class="mt-1 block text-xs font-normal leading-relaxed text-slate-500">
                                        If off, only city, province, postal code and country show under your company name (email, phone and website stay as configured).
                                    </span>
                                </span>
                            </label>
                        </div>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
                        <h4 class="text-sm font-semibold text-slate-900">Default document text</h4>
                        <p class="mt-0.5 text-xs text-slate-500">Pre-filled on new estimates.</p>
                        <div class="mt-4 space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Default notes</label>
                                <textarea
                                    v-model="form.estimate_default_notes"
                                    rows="3"
                                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Default terms</label>
                                <textarea
                                    v-model="form.estimate_default_terms"
                                    rows="4"
                                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                                />
                            </div>
                        </div>
                    </section>
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
                <h3 class="text-base font-semibold text-slate-900">Bank accounts</h3>
                <p class="mt-1 text-sm text-slate-500">
                    Add one or more accounts. Give each a title (e.g. “Primary”, “USD”) for your own reference and for invoice PDFs. Tick
                    <span class="font-medium text-slate-700">Show on invoice</span> for accounts that should appear on PDFs.
                </p>
                <div class="mt-4 space-y-6">
                    <div
                        v-for="(row, idx) in form.bank_accounts"
                        :key="idx"
                        class="rounded-lg border border-slate-200 bg-slate-50/80 p-4"
                    >
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <span class="text-sm font-medium text-slate-800">{{
                                (row.title ?? '').trim() ? (row.title ?? '').trim() : `Account ${idx + 1}`
                            }}</span>
                            <button
                                v-if="form.bank_accounts.length > 1"
                                type="button"
                                class="inline-flex items-center gap-1 rounded-md border border-rose-200 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50"
                                @click="removeBankAccount(idx)"
                            >
                                <Trash2 class="h-3.5 w-3.5" aria-hidden="true" />
                                Remove
                            </button>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs font-medium text-slate-500">Account title</label>
                                <AppInput v-model="row.title" placeholder="e.g. Primary account, USD, Operating" />
                                <p class="mt-1 text-xs text-slate-500">Shown on this card and on the invoice PDF above that account’s banking details.</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Bank name</label>
                                <AppInput v-model="row.bank_name" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Account holder</label>
                                <AppInput v-model="row.bank_account_holder" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Account number</label>
                                <AppInput v-model="row.bank_account_number" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">SWIFT</label>
                                <AppInput v-model="row.swift_code" placeholder="e.g. SBZAZAJJ" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">BIC</label>
                                <AppInput v-model="row.bic" placeholder="e.g. DEUTDEFF" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">IBAN</label>
                                <AppInput v-model="row.iban" placeholder="e.g. GB29NWBK60161331926819" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Routing / sort code</label>
                                <AppInput v-model="row.routing_sort_code" placeholder="e.g. 20-00-00" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Branch code</label>
                                <AppInput v-model="row.bank_branch_code" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">Account type</label>
                                <AppSelect
                                    :model-value="row.bank_account_type"
                                    :options="bank_account_types.map((b) => ({ label: b.label, value: b.value }))"
                                    @update:model-value="row.bank_account_type = $event"
                                />
                            </div>
                            <div class="flex items-end pb-1">
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input v-model="row.show_on_invoice" type="checkbox" class="rounded border-slate-300">
                                    Show on invoice
                                </label>
                            </div>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                        @click="addBankAccount"
                    >
                        <Plus class="h-4 w-4" aria-hidden="true" />
                        Add bank account
                    </button>
                </div>
            </AppCard>

            <AppCard v-show="tab === 'payment_pages'">
                <h3 class="text-base font-semibold text-slate-900">Online payments</h3>
                <p class="mt-1 text-sm text-slate-500">
                    Store gateway credentials for hosted checkout (Stripe, PayFast, PayPal, Netcash, SnapScan, Zapper). When online payment pages are on, invoice “Pay online”, the customer pay page, and the public PDF link use the enabled providers.
                </p>
                <div class="mt-4 rounded-lg border border-slate-300 bg-slate-100 px-4 py-3">
                    <label class="flex cursor-pointer items-start gap-3">
                        <input
                            v-model="form.payment_pages_enabled"
                            type="checkbox"
                            class="mt-1 rounded border-slate-300"
                        >
                        <span>
                            <span class="block text-sm font-semibold text-slate-900">Enable online payment pages</span>
                            <span class="mt-0.5 block text-sm text-slate-600">
                                When off, public pay URLs, QR codes, public invoice PDF, and hosted checkout are disabled. Gateway settings below are kept so you can turn this back on without re-entering keys.
                            </span>
                        </span>
                    </label>
                </div>
                <div
                    class="mt-4 space-y-5 transition-opacity"
                    :class="{ 'pointer-events-none opacity-45': !form.payment_pages_enabled }"
                >
                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-800">
                            <input v-model="form.payment_gateways.payfast.enabled" type="checkbox" class="rounded border-slate-300">
                            Enable PayFast
                        </label>
                        <div v-show="form.payment_gateways.payfast.enabled" class="mt-3 grid gap-3 md:grid-cols-3">
                            <div><label class="mb-1 block text-xs text-slate-500">Merchant ID</label><AppInput v-model="form.payment_gateways.payfast.merchant_id" /></div>
                            <div><label class="mb-1 block text-xs text-slate-500">Merchant Key</label><AppInput v-model="form.payment_gateways.payfast.merchant_key" /></div>
                            <div><label class="mb-1 block text-xs text-slate-500">Passphrase</label><AppInput v-model="form.payment_gateways.payfast.passphrase" /></div>
                        </div>
                    </div>
                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-800">
                            <input v-model="form.payment_gateways.stripe.enabled" type="checkbox" class="rounded border-slate-300">
                            Enable Stripe
                        </label>
                        <div v-show="form.payment_gateways.stripe.enabled" class="mt-3 grid gap-3 md:grid-cols-3">
                            <div><label class="mb-1 block text-xs text-slate-500">Publishable key</label><AppInput v-model="form.payment_gateways.stripe.publishable_key" /></div>
                            <div><label class="mb-1 block text-xs text-slate-500">Secret key</label><AppInput v-model="form.payment_gateways.stripe.secret_key" /></div>
                            <div><label class="mb-1 block text-xs text-slate-500">Webhook secret</label><AppInput v-model="form.payment_gateways.stripe.webhook_secret" /></div>
                        </div>
                    </div>
                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-800">
                            <input v-model="form.payment_gateways.paypal.enabled" type="checkbox" class="rounded border-slate-300">
                            Enable PayPal
                        </label>
                        <div v-show="form.payment_gateways.paypal.enabled" class="mt-3 grid gap-3 md:grid-cols-3">
                            <div><label class="mb-1 block text-xs text-slate-500">Client ID</label><AppInput v-model="form.payment_gateways.paypal.client_id" /></div>
                            <div><label class="mb-1 block text-xs text-slate-500">Client Secret</label><AppInput v-model="form.payment_gateways.paypal.client_secret" /></div>
                            <div>
                                <label class="mb-1 block text-xs text-slate-500">Environment</label>
                                <AppSelect
                                    :model-value="form.payment_gateways.paypal.environment"
                                    :options="[{ label: 'Sandbox', value: 'sandbox' }, { label: 'Live', value: 'live' }]"
                                    @update:model-value="form.payment_gateways.paypal.environment = $event"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-800">
                            <input v-model="form.payment_gateways.netcash.enabled" type="checkbox" class="rounded border-slate-300">
                            Enable Netcash
                        </label>
                        <div v-show="form.payment_gateways.netcash.enabled" class="mt-3 grid gap-3 md:grid-cols-2">
                            <div><label class="mb-1 block text-xs text-slate-500">Account ID</label><AppInput v-model="form.payment_gateways.netcash.account_id" /></div>
                            <div><label class="mb-1 block text-xs text-slate-500">Service key</label><AppInput v-model="form.payment_gateways.netcash.service_key" /></div>
                        </div>
                    </div>
                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-800">
                            <input v-model="form.payment_gateways.snapscan.enabled" type="checkbox" class="rounded border-slate-300">
                            Enable SnapScan
                        </label>
                        <div v-show="form.payment_gateways.snapscan.enabled" class="mt-3 grid gap-3 md:grid-cols-2">
                            <div><label class="mb-1 block text-xs text-slate-500">Merchant ID</label><AppInput v-model="form.payment_gateways.snapscan.merchant_id" /></div>
                            <div><label class="mb-1 block text-xs text-slate-500">API key</label><AppInput v-model="form.payment_gateways.snapscan.api_key" /></div>
                        </div>
                    </div>
                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-800">
                            <input v-model="form.payment_gateways.zapper.enabled" type="checkbox" class="rounded border-slate-300">
                            Enable Zapper
                        </label>
                        <div v-show="form.payment_gateways.zapper.enabled" class="mt-3 grid gap-3 md:grid-cols-2">
                            <div><label class="mb-1 block text-xs text-slate-500">Merchant ID</label><AppInput v-model="form.payment_gateways.zapper.merchant_id" /></div>
                            <div><label class="mb-1 block text-xs text-slate-500">API key</label><AppInput v-model="form.payment_gateways.zapper.api_key" /></div>
                        </div>
                    </div>
                </div>
            </AppCard>
        </div>

        <div class="mt-8 flex items-center justify-end border-t border-slate-200 pt-6">
            <ActionMessage :on="form.recentlySuccessful" class="me-3">
                Saved.
            </ActionMessage>
            <AppButton variant="primary" :class="{ 'opacity-25': form.processing }" :disabled="form.processing" @click="submit">
                {{ form.processing ? 'Saving…' : 'Save' }}
            </AppButton>
        </div>
    </AppLayout>
</template>
