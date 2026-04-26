<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import ApplicationMark from '@/Components/ApplicationMark.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const STORAGE_KEY = 'spennies_onboarding_v1';

type Industry = { value: string; label: string };
type MonthOpt = { value: number; label: string };
type BankType = { value: string; label: string };

type InitialPayload = {
    team_name: string;
    vat_registered: boolean;
    vat_number: string;
    financial_year_end_month: number;
    industry: string;
    invoice_default_payment_terms_days: number;
    invoice_prefix: string;
    invoice_next_sequence: number;
    bank_name: string;
    bank_account_holder: string;
    bank_account_number: string;
    bank_branch_code: string;
    bank_account_type: string;
};

const props = defineProps<{
    industries: Industry[];
    financial_year_months: MonthOpt[];
    bank_account_types: BankType[];
    initial: InitialPayload;
    session_wizard: Record<string, unknown> | null;
    session_step: number;
}>();

type Wizard = {
    companyName: string;
    vatRegistered: boolean;
    vatNumber: string;
    financialYearEndMonth: number;
    industry: string;
    hasExistingBooks: boolean;
    openingBank: string;
    openingAr: string;
    openingAp: string;
    paymentTermsDays: number;
    invoicePrefix: string;
    invoiceStartNumber: number;
    bankName: string;
    bankAccountHolder: string;
    bankAccountNumber: string;
    bankBranchCode: string;
    bankAccountType: string;
};

function defaultWizard(i: InitialPayload): Wizard {
    return {
        companyName: i.team_name,
        vatRegistered: i.vat_registered,
        vatNumber: i.vat_number,
        financialYearEndMonth: i.financial_year_end_month,
        industry: i.industry,
        hasExistingBooks: false,
        openingBank: '',
        openingAr: '',
        openingAp: '',
        paymentTermsDays: i.invoice_default_payment_terms_days,
        invoicePrefix: i.invoice_prefix,
        invoiceStartNumber: i.invoice_next_sequence,
        bankName: i.bank_name,
        bankAccountHolder: i.bank_account_holder,
        bankAccountNumber: i.bank_account_number,
        bankBranchCode: i.bank_branch_code,
        bankAccountType: i.bank_account_type,
    };
}

const step = ref(1);
const wizard = ref<Wizard>(defaultWizard(props.initial));
const logoFile: Ref<File | null> = ref(null);
const logoInput = ref<HTMLInputElement | null>(null);
const finishing = ref(false);
const fieldErrors = ref<Record<string, string>>({});

function persistLocal(): void {
    const payload = { version: 1, step: step.value, wizard: wizard.value, savedAt: Date.now() };
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
    } catch {
        /* ignore */
    }
}

function loadLocal(): { step?: number; wizard?: Partial<Wizard> } | null {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) {
            return null;
        }
        const p = JSON.parse(raw) as { version?: number; step?: number; wizard?: Partial<Wizard> };
        if (p.version !== 1 || !p.wizard) {
            return null;
        }
        return { step: p.step, wizard: p.wizard };
    } catch {
        return null;
    }
}

let progressTimer: ReturnType<typeof setTimeout> | null = null;
function queueProgressSave(): void {
    if (progressTimer) {
        clearTimeout(progressTimer);
    }
    progressTimer = setTimeout(() => {
        router.post(
            route('onboarding.progress'),
            { step: step.value, wizard: wizard.value },
            { preserveScroll: true, preserveState: true },
        );
    }, 500);
}

function fireConfetti(): void {
    void import('canvas-confetti').then((mod) => {
        const confetti = mod.default;
        void confetti({ particleCount: 120, spread: 70, origin: { y: 0.6 } });
    });
}

onMounted(() => {
    const local = loadLocal();
    if (local?.wizard) {
        wizard.value = { ...defaultWizard(props.initial), ...local.wizard };
        step.value = Math.min(5, Math.max(1, local.step ?? 1));
    } else if (props.session_wizard && typeof props.session_wizard === 'object') {
        wizard.value = {
            ...defaultWizard(props.initial),
            ...(props.session_wizard as Partial<Wizard>),
        };
        step.value = Math.min(5, Math.max(1, props.session_step ?? 1));
    }
    persistLocal();
    if (step.value === 5) {
        fireConfetti();
    }
});

watch([step, wizard], persistLocal, { deep: true });
watch([step, wizard], queueProgressSave, { deep: true });

watch(step, (s, prev) => {
    if (s === 5 && prev !== 5) {
        fireConfetti();
    }
});

function next(): void {
    fieldErrors.value = {};
    if (step.value === 1) {
        step.value = 2;
        return;
    }
    if (step.value === 2) {
        if (!wizard.value.companyName.trim()) {
            fieldErrors.value.companyName = 'Company name is required.';
            return;
        }
        if (wizard.value.vatRegistered && !/^4\d{9}$/.test(wizard.value.vatNumber.trim())) {
            fieldErrors.value.vatNumber = 'Enter a valid 10-digit SA VAT number.';
            return;
        }
        step.value = 3;
        return;
    }
    if (step.value === 3) {
        step.value = 4;
        return;
    }
    if (step.value === 4) {
        step.value = 5;
        return;
    }
}

function back(): void {
    if (step.value > 1) {
        step.value -= 1;
    }
}

function openLogo(): void {
    logoInput.value?.click();
}

function onLogoChange(ev: Event): void {
    const t = ev.target as HTMLInputElement;
    const f = t.files?.[0];
    logoFile.value = f ?? null;
}

function buildFormData(): FormData {
    const fd = new FormData();
    fd.append('company_name', wizard.value.companyName.trim());
    fd.append('vat_registered', wizard.value.vatRegistered ? '1' : '0');
    fd.append('vat_number', wizard.value.vatRegistered ? wizard.value.vatNumber.trim() : '');
    fd.append('financial_year_end_month', String(wizard.value.financialYearEndMonth));
    fd.append('industry', wizard.value.industry);
    fd.append('has_existing_books', wizard.value.hasExistingBooks ? '1' : '0');
    fd.append('opening_bank', wizard.value.openingBank || '');
    fd.append('opening_ar', wizard.value.openingAr || '');
    fd.append('opening_ap', wizard.value.openingAp || '');
    fd.append('invoice_default_payment_terms_days', String(wizard.value.paymentTermsDays));
    fd.append('invoice_prefix', wizard.value.invoicePrefix.trim());
    fd.append('invoice_next_sequence', String(wizard.value.invoiceStartNumber));
    fd.append('bank_name', wizard.value.bankName.trim());
    fd.append('bank_account_holder', wizard.value.bankAccountHolder.trim());
    fd.append('bank_account_number', wizard.value.bankAccountNumber.trim());
    fd.append('bank_branch_code', wizard.value.bankBranchCode.trim());
    fd.append('bank_account_type', wizard.value.bankAccountType);
    if (logoFile.value) {
        fd.append('logo', logoFile.value);
    }
    return fd;
}

function finish(): void {
    finishing.value = true;
    fieldErrors.value = {};
    router.post(route('onboarding.complete'), buildFormData(), {
        preserveScroll: true,
        onFinish: () => {
            finishing.value = false;
        },
        onError: (e) => {
            fieldErrors.value = e as Record<string, string>;
        },
        onSuccess: () => {
            try {
                localStorage.removeItem(STORAGE_KEY);
            } catch {
                /* ignore */
            }
        },
    });
}

function skip(): void {
    router.post(route('onboarding.skip'));
}

const industryLabel = computed(() => {
    const i = props.industries.find((x) => x.value === wizard.value.industry);
    return i?.label ?? '—';
});

const fyLabel = computed(
    () => props.financial_year_months.find((m) => m.value === wizard.value.financialYearEndMonth)?.label ?? '—',
);

const liveInvoicePreview = computed(() => {
    const y = new Date().getFullYear();
    const raw = (wizard.value.invoicePrefix || 'INV').trim().replace(/-+$/, '');
    const base = raw || 'INV';
    const seq = Math.max(1, Number(wizard.value.invoiceStartNumber) || 1);
    return `${base}-${y}-${String(seq).padStart(4, '0')}`;
});
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100">
        <Head title="Set up your company" />

        <header class="border-b border-slate-800 bg-slate-900/80 backdrop-blur">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-4">
                <Link href="/" class="flex items-center gap-2 text-slate-200 hover:text-white">
                    <ApplicationMark class="h-8 w-8" />
                    <span class="text-sm font-semibold tracking-tight">Spennies</span>
                </Link>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-slate-500">Step {{ step }} of 5</span>
                    <button
                        type="button"
                        class="text-xs font-medium text-slate-400 underline decoration-slate-600 underline-offset-2 hover:text-slate-200"
                        @click="skip"
                    >
                        Skip for now
                    </button>
                </div>
            </div>
            <div class="mx-auto max-w-3xl px-4 pb-4">
                <div class="flex gap-1">
                    <div
                        v-for="s in 5"
                        :key="s"
                        class="h-1 flex-1 rounded-full transition-colors"
                        :class="s <= step ? 'bg-emerald-500' : 'bg-slate-800'"
                    />
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-10">
            <div class="rounded-2xl border border-slate-800 bg-slate-900/60 p-8 shadow-xl shadow-slate-950/50">
                <!-- Step 1 -->
                <div v-if="step === 1" class="space-y-6">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-white">Welcome to Spennies</h1>
                        <p class="mt-3 text-sm leading-relaxed text-slate-400">
                            Spennies helps South African small businesses invoice clients, track expenses, stay on top of
                            VAT and provisional tax, and understand profit with clear reports — without spreadsheet chaos.
                        </p>
                    </div>
                    <div class="flex justify-end gap-3">
                        <PrimaryButton type="button" class="!bg-emerald-600 !tracking-normal hover:!bg-emerald-500" @click="next">
                            Let&rsquo;s set up your company
                        </PrimaryButton>
                    </div>
                </div>

                <!-- Step 2 -->
                <div v-else-if="step === 2" class="space-y-6">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-white">Company details</h1>
                        <p class="mt-1 text-sm text-slate-400">We&rsquo;ll use this on invoices, tax returns, and reports.</p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <InputLabel for="co_name" value="Company name" class="!text-slate-300" />
                            <TextInput
                                id="co_name"
                                v-model="wizard.companyName"
                                type="text"
                                class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                                autocomplete="organization"
                            />
                            <InputError :message="fieldErrors.company_name || fieldErrors.companyName" class="mt-1" />
                        </div>

                        <div class="flex items-start gap-3 rounded-lg border border-slate-800 bg-slate-950/40 p-4">
                            <Checkbox id="vat_reg" :checked="wizard.vatRegistered" @update:checked="wizard.vatRegistered = $event" />
                            <div class="flex-1">
                                <InputLabel for="vat_reg" value="Are you VAT registered?" class="!text-slate-300" />
                                <p class="mt-1 text-xs text-slate-500">You can change this later in company settings.</p>
                            </div>
                        </div>

                        <div v-if="wizard.vatRegistered">
                            <InputLabel for="vat_num" value="VAT number" class="!text-slate-300" />
                            <TextInput
                                id="vat_num"
                                v-model="wizard.vatNumber"
                                type="text"
                                class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                                placeholder="4XXXXXXXXX"
                                maxlength="10"
                            />
                            <InputError :message="fieldErrors.vat_number || fieldErrors.vatNumber" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="fy_end" value="Financial year end" class="!text-slate-300" />
                            <select
                                id="fy_end"
                                v-model.number="wizard.financialYearEndMonth"
                                class="mt-1 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
                            >
                                <option v-for="m in financial_year_months" :key="m.value" :value="m.value">
                                    {{ m.label }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Defaults to February for many SA companies.</p>
                        </div>

                        <div>
                            <InputLabel for="industry" value="Industry" class="!text-slate-300" />
                            <select
                                id="industry"
                                v-model="wizard.industry"
                                class="mt-1 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
                            >
                                <option value="">Select…</option>
                                <option v-for="i in industries" :key="i.value" :value="i.value">
                                    {{ i.label }}
                                </option>
                            </select>
                            <InputError :message="fieldErrors.industry" class="mt-1" />
                        </div>
                    </div>

                    <div class="flex justify-between gap-3">
                        <button
                            type="button"
                            class="text-sm font-medium text-slate-400 hover:text-slate-200"
                            @click="back"
                        >
                            Back
                        </button>
                        <PrimaryButton type="button" class="!bg-emerald-600 !tracking-normal hover:!bg-emerald-500" @click="next">
                            Continue
                        </PrimaryButton>
                    </div>
                </div>

                <!-- Step 3 -->
                <div v-else-if="step === 3" class="space-y-6">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-white">Opening balances</h1>
                        <p class="mt-1 text-sm text-slate-400">Bring across your current position from a previous system.</p>
                    </div>

                    <div class="flex items-start gap-3 rounded-lg border border-slate-800 bg-slate-950/40 p-4">
                        <Checkbox id="books" :checked="wizard.hasExistingBooks" @update:checked="wizard.hasExistingBooks = $event" />
                        <div class="flex-1">
                            <InputLabel for="books" value="Do you have existing books?" class="!text-slate-300" />
                            <p class="mt-1 text-xs text-slate-500">If not, we&rsquo;ll start from zero.</p>
                        </div>
                    </div>

                    <div v-if="wizard.hasExistingBooks" class="space-y-4 rounded-xl border border-slate-800 bg-slate-950/30 p-4">
                        <p class="text-sm text-slate-400">
                            Enter balances in <span class="text-slate-200">rand</span> for the main control accounts. We&rsquo;ll post a single
                            opening entry balanced against owner&rsquo;s equity.
                        </p>
                        <div>
                            <InputLabel for="ob_bank" value="Cash / bank (1010)" class="!text-slate-300" />
                            <TextInput
                                id="ob_bank"
                                v-model="wizard.openingBank"
                                type="text"
                                class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                                placeholder="0.00"
                            />
                            <InputError :message="fieldErrors.opening_bank" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="ob_ar" value="Accounts receivable (1100)" class="!text-slate-300" />
                            <TextInput
                                id="ob_ar"
                                v-model="wizard.openingAr"
                                type="text"
                                class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                                placeholder="0.00"
                            />
                            <InputError :message="fieldErrors.opening_ar" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="ob_ap" value="Accounts payable (2000)" class="!text-slate-300" />
                            <TextInput
                                id="ob_ap"
                                v-model="wizard.openingAp"
                                type="text"
                                class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                                placeholder="0.00"
                            />
                            <InputError :message="fieldErrors.opening_ap" class="mt-1" />
                        </div>
                    </div>

                    <p class="text-xs leading-relaxed text-slate-500">
                        You can add detailed opening balances from Chart of Accounts later.
                    </p>

                    <div class="flex justify-between gap-3">
                        <button
                            type="button"
                            class="text-sm font-medium text-slate-400 hover:text-slate-200"
                            @click="back"
                        >
                            Back
                        </button>
                        <PrimaryButton type="button" class="!bg-emerald-600 !tracking-normal hover:!bg-emerald-500" @click="next">
                            Continue
                        </PrimaryButton>
                    </div>
                </div>

                <!-- Step 4 -->
                <div v-else-if="step === 4" class="space-y-6">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-white">Invoice setup</h1>
                        <p class="mt-1 text-sm text-slate-400">Logo and banking details appear on PDF invoices.</p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <InputLabel value="Company logo" class="!text-slate-300" />
                            <div class="mt-2 flex flex-wrap items-center gap-3">
                                <input
                                    ref="logoInput"
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="onLogoChange"
                                >
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 hover:border-slate-600"
                                    @click="openLogo"
                                >
                                    {{ logoFile ? 'Change image' : 'Upload logo' }}
                                </button>
                                <span v-if="logoFile" class="text-xs text-slate-500">{{ logoFile.name }}</span>
                            </div>
                            <InputError :message="fieldErrors.logo" class="mt-1" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <InputLabel for="b_name" value="Bank name" class="!text-slate-300" />
                                <TextInput
                                    id="b_name"
                                    v-model="wizard.bankName"
                                    type="text"
                                    class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                                />
                                <InputError :message="fieldErrors.bank_name" class="mt-1" />
                            </div>
                            <div class="sm:col-span-2">
                                <InputLabel for="b_holder" value="Account holder" class="!text-slate-300" />
                                <TextInput
                                    id="b_holder"
                                    v-model="wizard.bankAccountHolder"
                                    type="text"
                                    class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                                />
                                <InputError :message="fieldErrors.bank_account_holder" class="mt-1" />
                            </div>
                            <div>
                                <InputLabel for="b_num" value="Account number" class="!text-slate-300" />
                                <TextInput
                                    id="b_num"
                                    v-model="wizard.bankAccountNumber"
                                    type="text"
                                    class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                                />
                                <InputError :message="fieldErrors.bank_account_number" class="mt-1" />
                            </div>
                            <div>
                                <InputLabel for="b_branch" value="Branch code" class="!text-slate-300" />
                                <TextInput
                                    id="b_branch"
                                    v-model="wizard.bankBranchCode"
                                    type="text"
                                    class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                                />
                                <InputError :message="fieldErrors.bank_branch_code" class="mt-1" />
                            </div>
                            <div class="sm:col-span-2">
                                <InputLabel for="b_type" value="Account type" class="!text-slate-300" />
                                <select
                                    id="b_type"
                                    v-model="wizard.bankAccountType"
                                    class="mt-1 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
                                >
                                    <option v-for="b in bank_account_types" :key="b.value" :value="b.value">
                                        {{ b.label }}
                                    </option>
                                </select>
                                <InputError :message="fieldErrors.bank_account_type" class="mt-1" />
                            </div>
                        </div>

                        <div>
                            <InputLabel for="pay_terms" value="Default payment terms (days)" class="!text-slate-300" />
                            <TextInput
                                id="pay_terms"
                                v-model.number="wizard.paymentTermsDays"
                                type="number"
                                min="0"
                                max="365"
                                class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                            />
                            <InputError :message="fieldErrors.invoice_default_payment_terms_days" class="mt-1" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="inv_pref" value="Invoice prefix" class="!text-slate-300" />
                                <TextInput
                                    id="inv_pref"
                                    v-model="wizard.invoicePrefix"
                                    type="text"
                                    class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                                />
                                <InputError :message="fieldErrors.invoice_prefix" class="mt-1" />
                            </div>
                            <div>
                                <InputLabel for="inv_seq" value="Starting invoice number" class="!text-slate-300" />
                                <TextInput
                                    id="inv_seq"
                                    v-model.number="wizard.invoiceStartNumber"
                                    type="number"
                                    min="1"
                                    class="mt-1 block w-full border-slate-700 bg-slate-950 text-slate-100"
                                />
                                <InputError :message="fieldErrors.invoice_next_sequence" class="mt-1" />
                            </div>
                        </div>
                        <p class="text-xs text-slate-500">
                            Next invoice preview:
                            <span class="font-mono text-slate-300">{{ liveInvoicePreview }}</span>
                        </p>
                    </div>

                    <div class="flex justify-between gap-3">
                        <button
                            type="button"
                            class="text-sm font-medium text-slate-400 hover:text-slate-200"
                            @click="back"
                        >
                            Back
                        </button>
                        <PrimaryButton type="button" class="!bg-emerald-600 !tracking-normal hover:!bg-emerald-500" @click="next">
                            Continue
                        </PrimaryButton>
                    </div>
                </div>

                <!-- Step 5 -->
                <div v-else-if="step === 5" class="space-y-6">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-white">You&rsquo;re all set</h1>
                        <p class="mt-1 text-sm text-slate-400">Here&rsquo;s what we&rsquo;ll save when you finish.</p>
                    </div>

                    <ul class="space-y-3 rounded-xl border border-slate-800 bg-slate-950/40 p-4 text-sm text-slate-300">
                        <li>
                            <span class="text-slate-500">Company:</span>
                            {{ wizard.companyName }}
                        </li>
                        <li>
                            <span class="text-slate-500">VAT:</span>
                            {{ wizard.vatRegistered ? wizard.vatNumber : 'Not registered' }}
                        </li>
                        <li>
                            <span class="text-slate-500">Year end:</span>
                            {{ fyLabel }} ·
                            <span class="text-slate-500">Industry:</span>
                            {{ industryLabel }}
                        </li>
                        <li>
                            <span class="text-slate-500">Opening balances:</span>
                            {{
                                wizard.hasExistingBooks
                                    ? 'Bank / AR / AP from step 3'
                                    : 'Starting from zero'
                            }}
                        </li>
                        <li>
                            <span class="text-slate-500">Invoices:</span>
                            {{ liveInvoicePreview }}, {{ wizard.paymentTermsDays }}-day terms
                        </li>
                    </ul>

                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Quick links (after finish)</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span
                                class="rounded-full border border-slate-700 bg-slate-950 px-3 py-1 text-xs text-slate-400"
                            >
                                Create first invoice
                            </span>
                            <span
                                class="rounded-full border border-slate-700 bg-slate-950 px-3 py-1 text-xs text-slate-400"
                            >
                                Add first expense
                            </span>
                            <span
                                class="rounded-full border border-slate-700 bg-slate-950 px-3 py-1 text-xs text-slate-400"
                            >
                                Explore reports
                            </span>
                        </div>
                        <p class="mt-2 text-xs text-slate-600">
                            Use the sidebar from your dashboard — finish setup below to unlock navigation everywhere.
                        </p>
                    </div>

                    <div class="flex justify-between gap-3">
                        <button
                            type="button"
                            class="text-sm font-medium text-slate-400 hover:text-slate-200"
                            @click="back"
                        >
                            Back
                        </button>
                        <PrimaryButton
                            type="button"
                            class="!bg-emerald-600 !tracking-normal hover:!bg-emerald-500 disabled:opacity-50"
                            :disabled="finishing"
                            @click="finish"
                        >
                            {{ finishing ? 'Saving…' : 'Finish setup' }}
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
