<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, watch, withDefaults } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { useToast } from '@/Composables/useToast';
import { Camera, Plus, ScanLine, Upload, X } from 'lucide-vue-next';
import { FALLBACK_EXPENSE_TAX_RATES, type ExpenseTaxRateOption } from './fallbackTaxRates';

type CategoryOption = { id: number; name: string };
type PaidFromOption = { id: number; name: string; gl_account_id: number; gl_label: string };
type SupplierOption = { id: number; name: string };
type TaxRateOption = ExpenseTaxRateOption;

type ExpenseAttachment = {
    id: number;
    name: string;
    mime_type: string;
    size: number;
    url: string;
};

type ExpenseFormRow = {
    id: number;
    date: string | null;
    supplier_id: number;
    supplier_custom: string;
    category_account_id: number;
    description: string;
    amount_excl_vat: number;
    vat_rate: 'vat15' | 'vat0' | 'exempt' | 'no_vat';
    vat_amount: number;
    paid_from_banking_account_id: number;
    reference: string;
    notes: string;
    office_percentage: number;
    distance_km: number;
    rate_per_km: number;
    attachments?: ExpenseAttachment[];
};

const props = withDefaults(
    defineProps<{
        isEditing: boolean;
        expense: ExpenseFormProps | null;
        prefill: { supplier_id: number; supplier_custom: string } | null;
        categories: CategoryOption[];
        paid_from_options: PaidFromOption[];
        supplier_options: SupplierOption[];
        tax_rates: TaxRateOption[];
        sars_rate_per_km: number;
    }>(),
    {
        isEditing: false,
        expense: null,
        prefill: null,
        categories: () => [],
        paid_from_options: () => [],
        supplier_options: () => [],
        tax_rates: () => FALLBACK_EXPENSE_TAX_RATES,
        sars_rate_per_km: 4.84,
    },
);

const categoryList = computed(() => props.categories);
const paidFromList = computed(() => props.paid_from_options);
const createdSuppliers = ref<SupplierOption[]>([]);
const supplierList = computed(() => {
    const byId = new Map<number, SupplierOption>();
    for (const supplier of [...props.supplier_options, ...createdSuppliers.value]) {
        byId.set(supplier.id, supplier);
    }

    return [...byId.values()].sort((a, b) => a.name.localeCompare(b.name));
});
const taxRateList = computed(() => (props.tax_rates?.length ? props.tax_rates : FALLBACK_EXPENSE_TAX_RATES));

const defaultPaidFromAccountId = (): number => {
    const bank = paidFromList.value.find((option) => option.gl_label.startsWith('1010'));
    if (bank) {
        return bank.id;
    }

    return paidFromList.value[0]?.id ?? 0;
};

const page = usePage();
const toast = useToast();
const aiEnabled = computed(() => Boolean(page.props.ai_enabled));
const scanningKey = ref<string | null>(null);
const scanReceiptError = ref<string | null>(null);
const scanReceiptApplied = ref(false);
const receiptUploadSuccess = ref<string | null>(null);
const saveSupplierLoading = ref(false);
const saveSupplierError = ref<string | null>(null);
const scanReceiptLoading = computed(() => scanningKey.value !== null);

const schema = z
    .object({
        date: z.string().min(1),
        supplier_id: z.coerce.number().int().min(0),
        supplier_custom: z.string().optional(),
        category_account_id: z.coerce.number().int().positive(),
        description: z.string().optional(),
        amount_excl_vat: z.coerce.number().min(0),
        vat_rate: z.enum(['vat15', 'vat0', 'exempt', 'no_vat']),
        vat_amount: z.coerce.number().min(0),
        paid_from_banking_account_id: z.coerce.number().int().positive(),
        reference: z.string().optional(),
        notes: z.string().optional(),
        office_percentage: z.coerce.number().min(0).max(100).optional(),
        distance_km: z.coerce.number().min(0).optional(),
        rate_per_km: z.coerce.number().min(0).optional(),
    })
    .refine((data) => data.supplier_id > 0 || (data.supplier_custom?.trim().length ?? 0) > 0, {
        path: ['supplier_custom'],
        message: 'Choose a saved supplier or enter a one-off name',
    });

const initialFromProps = () => {
    if (props.isEditing && props.expense) {
        const e = props.expense;
        return {
            date: e.date ?? new Date().toISOString().slice(0, 10),
            supplier_id: e.supplier_id,
            supplier_custom: e.supplier_custom,
            category_account_id: e.category_account_id || 0,
            description: e.description,
            amount_excl_vat: e.amount_excl_vat,
            vat_rate: e.vat_rate,
            vat_amount: e.vat_amount,
            paid_from_banking_account_id: e.paid_from_banking_account_id || defaultPaidFromAccountId(),
            reference: e.reference,
            notes: e.notes,
            office_percentage: e.office_percentage,
            distance_km: e.distance_km,
            rate_per_km: e.rate_per_km || props.sars_rate_per_km,
        };
    }
    const p = props.prefill;
    return {
        date: new Date().toISOString().slice(0, 10),
        supplier_id: p?.supplier_id && p.supplier_id > 0 ? p.supplier_id : 0,
        supplier_custom: p?.supplier_custom ?? '',
        category_account_id: 0,
        description: '',
        amount_excl_vat: 0,
        vat_rate: 'vat15' as const,
        vat_amount: 0,
        paid_from_banking_account_id: defaultPaidFromAccountId(),
        reference: '',
        notes: '',
        office_percentage: 15,
        distance_km: 0,
        rate_per_km: props.sars_rate_per_km,
    };
};

const form = reactive(initialFromProps());

watch(
    () => [form.supplier_id, form.supplier_custom],
    () => {
        saveSupplierError.value = null;
    },
);

const receiptFiles = ref<File[]>([]);
const receiptPreviewUrls = ref<string[]>([]);
const showAdvanced = ref(false);

const selectedTax = computed(
    () => taxRateList.value.find((rate) => rate.value === form.vat_rate) ?? taxRateList.value[0] ?? FALLBACK_EXPENSE_TAX_RATES[0],
);
const vatAutoCents = computed(() => Math.round(Number(form.amount_excl_vat || 0) * Number(selectedTax.value?.rate || 0) * 100));
const totalCents = computed(() => Math.round(Number(form.amount_excl_vat || 0) * 100) + Math.round(Number(form.vat_amount || 0) * 100));

const totalInclVat = computed({
    get: () => totalCents.value / 100,
    set: (value: string | number) => {
        const total = Number(value);
        if (!Number.isFinite(total) || total < 0) {
            return;
        }
        const rate = Number(selectedTax.value?.rate || 0);
        const totalC = Math.round(total * 100);
        if (rate > 0) {
            form.amount_excl_vat = Math.round(totalC / (1 + rate)) / 100;
        } else {
            form.amount_excl_vat = totalC / 100;
        }
    },
});

const selectedCategory = computed(() => {
    const id = Number(form.category_account_id || 0);
    return categoryList.value.find((category) => category.id === id);
});
const isHomeOffice = computed(
    () => selectedCategory.value?.name?.toLowerCase().includes('home office') ?? false,
);
const isTravel = computed(() => selectedCategory.value?.name?.toLowerCase().includes('travel') ?? false);
const travelDeduction = computed(() => Number(form.distance_km || 0) * Number(form.rate_per_km || 0));

watch(
    () => [form.amount_excl_vat, form.vat_rate],
    () => {
        form.vat_amount = vatAutoCents.value / 100;
    },
    { immediate: true },
);

const isImageMime = (mime: string, name = '') =>
    mime.startsWith('image/') || /\.(jpe?g|png|gif|webp|heic)$/i.test(name);
const isPdfMime = (mime: string, name = '') =>
    mime === 'application/pdf' || /\.pdf$/i.test(name);

const isImageFile = (file: File) => isImageMime(file.type, file.name);
const isPdfFile = (file: File) => isPdfMime(file.type, file.name);

const previewUrlFor = (file: File) => {
    if (isImageFile(file) || isPdfFile(file)) {
        return URL.createObjectURL(file);
    }
    return '';
};

const existingAttachments = ref<ExpenseAttachment[]>([...(props.expense?.attachments ?? [])]);
/** Staged for deletion on Update — not removed from disk until save. */
const pendingRemoveAttachmentIds = ref<number[]>([]);

watch(
    () => props.expense?.attachments,
    (attachments) => {
        existingAttachments.value = [...(attachments ?? [])];
        pendingRemoveAttachmentIds.value = [];
    },
);

const visibleExistingAttachments = computed(() => {
    const pending = new Set(pendingRemoveAttachmentIds.value);
    return existingAttachments.value.filter((attachment) => !pending.has(attachment.id));
});

type ReceiptPreviewItem =
    | { kind: 'existing'; attachment: ExpenseAttachment }
    | { kind: 'new'; file: File; url: string; index: number };

const receiptPreviewTarget = ref<ReceiptPreviewItem | null>(null);

const openExistingAttachmentPreview = (attachment: ExpenseAttachment) => {
    if (!isImageMime(attachment.mime_type, attachment.name) && !isPdfMime(attachment.mime_type, attachment.name)) {
        window.open(attachment.url, '_blank', 'noopener');
        return;
    }
    receiptPreviewTarget.value = { kind: 'existing', attachment };
};

const removeExistingAttachment = (attachment: ExpenseAttachment) => {
    if (!props.isEditing || !props.expense?.id) {
        return;
    }

    if (pendingRemoveAttachmentIds.value.includes(attachment.id)) {
        return;
    }

    if (
        !window.confirm(
            `Remove “${attachment.name}” from this expense? It will only be deleted when you click Update Expense. Cancel leaves it unchanged.`,
        )
    ) {
        return;
    }

    const current = receiptPreviewTarget.value;
    if (current?.kind === 'existing' && current.attachment.id === attachment.id) {
        receiptPreviewTarget.value = null;
    }

    pendingRemoveAttachmentIds.value = [...pendingRemoveAttachmentIds.value, attachment.id];
};

const openReceiptPreview = (index: number) => {
    const url = receiptPreviewUrls.value[index];
    const file = receiptFiles.value[index];
    if (!file || !url) {
        return;
    }
    receiptPreviewTarget.value = { kind: 'new', file, url, index };
};

const closeReceiptPreview = () => {
    receiptPreviewTarget.value = null;
};

const previewedReceipt = computed(() => {
    const target = receiptPreviewTarget.value;
    if (!target) {
        return null;
    }
    if (target.kind === 'existing') {
        return {
            name: target.attachment.name,
            url: target.attachment.url,
            isImage: isImageMime(target.attachment.mime_type, target.attachment.name),
            isPdf: isPdfMime(target.attachment.mime_type, target.attachment.name),
        };
    }

    return {
        name: target.file.name,
        url: target.url,
        isImage: isImageFile(target.file),
        isPdf: isPdfFile(target.file),
    };
});

watch(receiptPreviewTarget, (target, _prev, onCleanup) => {
    if (target === null) {
        return;
    }

    const onKeydown = (event: KeyboardEvent) => {
        if (event.key === 'Escape') {
            closeReceiptPreview();
        }
    };
    window.addEventListener('keydown', onKeydown);
    onCleanup(() => window.removeEventListener('keydown', onKeydown));
});

onUnmounted(() => {
    receiptPreviewTarget.value = null;
    receiptPreviewUrls.value.forEach((url) => {
        if (url) URL.revokeObjectURL(url);
    });
});

const onReceiptChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const incoming = Array.from(input.files ?? []);
    if (!incoming.length) return;

    const previousCount = receiptFiles.value.length;
    const nextFiles = [...receiptFiles.value, ...incoming].slice(0, 20);
    const added = nextFiles.length - previousCount;
    receiptPreviewUrls.value.forEach((url) => {
        if (url) URL.revokeObjectURL(url);
    });
    receiptFiles.value = nextFiles;
    receiptPreviewUrls.value = nextFiles.map((file) => previewUrlFor(file));
    scanReceiptError.value = null;
    scanReceiptApplied.value = false;
    receiptUploadSuccess.value = added === 1
        ? 'Receipt added — it will be saved with this expense.'
        : added > 1
            ? `${added} receipts added — they will be saved with this expense.`
            : 'Receipt limit reached (20 files).';
    input.value = '';
};

const removeReceiptAt = (index: number) => {
    const file = receiptFiles.value[index];
    const label = file?.name?.trim() || 'this file';
    if (!window.confirm(`Remove “${label}” from this expense?`)) {
        return;
    }

    const current = receiptPreviewTarget.value;
    if (current?.kind === 'new') {
        if (current.index === index) {
            receiptPreviewTarget.value = null;
        } else if (current.index > index) {
            receiptPreviewTarget.value = { ...current, index: current.index - 1 };
        }
    }
    const url = receiptPreviewUrls.value[index];
    if (url) URL.revokeObjectURL(url);
    receiptFiles.value = receiptFiles.value.filter((_, i) => i !== index);
    receiptPreviewUrls.value = receiptPreviewUrls.value.filter((_, i) => i !== index);
};

const clearReceipts = () => {
    if (!receiptFiles.value.length) {
        return;
    }
    if (!window.confirm('Remove all newly added receipts from this expense?')) {
        return;
    }

    receiptPreviewTarget.value = null;
    receiptPreviewUrls.value.forEach((url) => {
        if (url) URL.revokeObjectURL(url);
    });
    receiptFiles.value = [];
    receiptPreviewUrls.value = [];
    scanReceiptApplied.value = false;
    scanReceiptError.value = null;
    receiptUploadSuccess.value = null;
};

const totalReceiptCount = computed(() => visibleExistingAttachments.value.length + receiptFiles.value.length);

type ScanPayload = {
    date?: string | null;
    supplier_id?: number;
    supplier?: string;
    description?: string;
    amount_excl_vat?: number | null;
    vat_amount?: number | null;
    vat_rate?: 'vat15' | 'vat0' | 'exempt' | 'no_vat' | null;
    reference?: string;
};

const applyScanPayload = (data: ScanPayload) => {
    if (data.date) form.date = data.date;
    if (typeof data.supplier_id === 'number' && data.supplier_id > 0) {
        form.supplier_id = data.supplier_id;
        form.supplier_custom = '';
    } else if (data.supplier?.trim()) {
        form.supplier_id = 0;
        form.supplier_custom = data.supplier.trim();
    }
    if (data.description != null) form.description = data.description;
    if (data.vat_rate) form.vat_rate = data.vat_rate;
    if (data.amount_excl_vat != null) form.amount_excl_vat = data.amount_excl_vat;
    if (data.vat_amount != null) form.vat_amount = data.vat_amount;
    if (data.reference != null) form.reference = data.reference;
};

const postScan = async (body: FormData) => {
    const token = page.props.csrf_token as string | undefined;
    if (!token) {
        throw new Error('Unable to scan: missing security token. Refresh the page and try again.');
    }

    let url: string;
    try {
        url = route('expenses.parse-receipt');
    } catch {
        // Ziggy can lag behind after deploys / route:cache; path is stable.
        url = '/expenses/parse-receipt';
    }

    let res: Response;
    try {
        res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
            },
            body,
        });
    } catch {
        throw new Error('Could not reach the scanning service (network). Check the server can reach the AI provider.');
    }

    const payload = (await res.json().catch(() => null)) as {
        data?: ScanPayload;
        message?: string;
        errors?: Record<string, string[]>;
    } | null;

    if (!res.ok) {
        const firstError = payload?.errors
            ? Object.values(payload.errors).flat()[0]
            : null;
        throw new Error(firstError || payload?.message || `Scan failed (HTTP ${res.status}).`);
    }

    const data = payload?.data;
    if (!data) {
        throw new Error('Could not scan this receipt.');
    }

    applyScanPayload(data);
    scanReceiptApplied.value = true;
};

const runScan = async (work: () => Promise<void>) => {
    scanReceiptError.value = null;
    scanReceiptApplied.value = false;
    try {
        await work();
    } catch (error) {
        scanReceiptError.value =
            error instanceof Error && error.message
                ? error.message
                : 'Could not reach the scanning service. Try again.';
    }
};

const scanExistingAttachment = async (attachment: ExpenseAttachment) => {
    if (!aiEnabled.value || scanningKey.value || !props.expense?.id) {
        return;
    }

    scanningKey.value = `existing:${attachment.id}`;
    await runScan(async () => {
        const body = new FormData();
        body.append('attachment_id', String(attachment.id));
        body.append('transaction_id', String(props.expense!.id));
        await postScan(body);
    });
    scanningKey.value = null;
};

const scanNewReceipt = async (index: number) => {
    if (!aiEnabled.value || scanningKey.value) {
        return;
    }
    const file = receiptFiles.value[index];
    if (!file) {
        return;
    }

    scanningKey.value = `new:${index}`;
    await runScan(async () => {
        const body = new FormData();
        body.append('receipt', file);
        await postScan(body);
    });
    scanningKey.value = null;
};

const scanAllReceipts = async () => {
    if (!aiEnabled.value || scanningKey.value || totalReceiptCount.value < 2) {
        return;
    }

    scanningKey.value = 'all';
    await runScan(async () => {
        const body = new FormData();
        receiptFiles.value.forEach((file, index) => {
            body.append(`receipts[${index}]`, file);
        });
        if (visibleExistingAttachments.value.length && props.expense?.id) {
            body.append('transaction_id', String(props.expense.id));
            visibleExistingAttachments.value.forEach((attachment, index) => {
                body.append(`attachment_ids[${index}]`, String(attachment.id));
            });
        }
        await postScan(body);
    });
    scanningKey.value = null;
};

const supplierSelectOptions = computed(() => [
    { label: 'Custom (one-off)', value: '0' },
    ...supplierList.value.map((s) => ({ label: s.name, value: String(s.id) })),
]);

const canSaveAsSupplier = computed(
    () => form.supplier_id === 0 && (form.supplier_custom?.trim().length ?? 0) > 0,
);

const expenseReturnPath = computed(() =>
    props.isEditing && props.expense ? `/expenses/${props.expense.id}/edit` : '/expenses/create',
);

const openNewSupplierForm = () => {
    const query: Record<string, string> = { return: expenseReturnPath.value };
    const name = form.supplier_custom?.trim();
    if (form.supplier_id === 0 && name) {
        query.name = name;
    }
    router.get(route('suppliers.create'), query);
};

const saveAsSupplier = async () => {
    const name = form.supplier_custom?.trim() ?? '';
    if (!name || saveSupplierLoading.value) {
        return;
    }

    const token = page.props.csrf_token as string | undefined;
    if (!token) {
        saveSupplierError.value = 'Unable to save supplier: missing security token. Refresh the page and try again.';
        return;
    }

    saveSupplierLoading.value = true;
    saveSupplierError.value = null;

    try {
        const res = await fetch(route('suppliers.store'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify({
                name,
                contact_name: null,
                email: null,
                phone: null,
                vat_number: null,
                registration_number: null,
                address: null,
                notes: null,
                is_active: true,
            }),
        });

        const payload = (await res.json().catch(() => null)) as {
            data?: { id?: number; name?: string };
            message?: string;
            errors?: Record<string, string[]>;
        } | null;

        if (!res.ok) {
            const firstError = payload?.errors
                ? Object.values(payload.errors).flat()[0]
                : null;
            saveSupplierError.value = firstError || payload?.message || 'Could not save this supplier.';
            toast.error(saveSupplierError.value);
            return;
        }

        const id = Number(payload?.data?.id ?? 0);
        const savedName = String(payload?.data?.name ?? name);
        if (id <= 0) {
            saveSupplierError.value = 'Could not save this supplier.';
            toast.error(saveSupplierError.value);
            return;
        }

        createdSuppliers.value = [...createdSuppliers.value, { id, name: savedName }];
        form.supplier_id = id;
        form.supplier_custom = '';
        toast.success('Supplier saved and selected.');
    } catch {
        saveSupplierError.value = 'Could not save this supplier. Try again.';
        toast.error(saveSupplierError.value);
    } finally {
        saveSupplierLoading.value = false;
    }
};

const categorySelectOptions = computed(() =>
    categoryList.value.map((category) => ({ label: category.name, value: String(category.id) })),
);

const taxRateSelectOptions = computed(() =>
    taxRateList.value.map((rate) => ({ label: rate.label, value: rate.value })),
);

const hasCategories = computed(() => categoryList.value.length > 0);

const buildFormData = (parsed: z.infer<typeof schema>) => {
    const data = new FormData();
    data.set('date', parsed.date);
    if (parsed.supplier_id > 0) {
        data.set('supplier_id', String(parsed.supplier_id));
    } else {
        data.set('supplier', parsed.supplier_custom?.trim() ?? '');
    }
    data.set('category_account_id', String(parsed.category_account_id));
    data.set('description', parsed.description ?? '');
    data.set('amount_excl_vat_cents', String(Math.round(parsed.amount_excl_vat * 100)));
    data.set('vat_rate', parsed.vat_rate);
    data.set('vat_amount_cents', String(Math.round(parsed.vat_amount * 100)));
    data.set('paid_from_banking_account_id', String(parsed.paid_from_banking_account_id));
    data.set('reference', parsed.reference ?? '');
    data.set('notes', parsed.notes ?? '');
    if (isHomeOffice.value) data.set('office_percentage', String(parsed.office_percentage ?? 0));
    if (isTravel.value) {
        data.set('distance_km', String(parsed.distance_km ?? 0));
        data.set('rate_per_km', String(parsed.rate_per_km ?? props.sars_rate_per_km));
    }
    receiptFiles.value.forEach((file, index) => {
        data.append(`receipts[${index}]`, file);
    });
    pendingRemoveAttachmentIds.value.forEach((id, index) => {
        data.append(`remove_attachment_ids[${index}]`, String(id));
    });
    return data;
};

const formErrors = ref<string[]>([]);
const submitting = ref(false);

const inertiaErrors = computed(() => {
    const errors = page.props.errors as Record<string, string | string[]> | undefined;
    if (!errors) {
        return [];
    }

    return Object.values(errors).flatMap((value) => (Array.isArray(value) ? value : [value]));
});

const visibleErrors = computed(() => (formErrors.value.length ? formErrors.value : inertiaErrors.value));

const snapshotForm = () => ({
    date: String(form.date ?? ''),
    supplier_id: Number(form.supplier_id || 0),
    supplier_custom: String(form.supplier_custom ?? ''),
    category_account_id: Number(form.category_account_id || 0),
    description: String(form.description ?? ''),
    amount_excl_vat: Number(form.amount_excl_vat || 0),
    vat_rate: form.vat_rate,
    vat_amount: Number(form.vat_amount || 0),
    paid_from_banking_account_id: Number(form.paid_from_banking_account_id || 0),
    reference: String(form.reference ?? ''),
    notes: String(form.notes ?? ''),
    office_percentage: Number(form.office_percentage || 0),
    distance_km: Number(form.distance_km || 0),
    rate_per_km: Number(form.rate_per_km || props.sars_rate_per_km),
});

const submit = () => {
    if (submitting.value) {
        return;
    }

    formErrors.value = [];
    if (!hasCategories.value) {
        formErrors.value = ['Add at least one expense category before saving.'];
        return;
    }

    const parsed = schema.safeParse(snapshotForm());
    if (!parsed.success) {
        const messages = parsed.error.issues.map((issue) => {
            const field = issue.path[0];
            if (field === 'supplier_custom' || field === 'supplier_id') {
                return 'Choose a saved supplier or enter a one-off supplier name.';
            }
            if (field === 'category_account_id') {
                return 'Choose an expense category.';
            }
            if (field === 'date') {
                return 'Enter a date.';
            }
            if (field === 'amount_excl_vat') {
                return 'Enter an amount excluding VAT.';
            }
            if (field === 'paid_from_banking_account_id') {
                return 'Choose which account this was paid from.';
            }
            if (field === 'vat_rate') {
                return 'Choose a VAT rate.';
            }

            return issue.message;
        });
        formErrors.value = [...new Set(messages)];
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
    }

    const visitOptions = {
        onStart: () => {
            submitting.value = true;
        },
        onError: (errors: Record<string, string>) => {
            formErrors.value = Object.values(errors).flat();
            if (!formErrors.value.length) {
                formErrors.value = ['Could not save this expense. Check the form and try again.'];
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        onFinish: () => {
            submitting.value = false;
        },
    };

    const hasNewReceipts = receiptFiles.value.length > 0;
    const hasRemovals = pendingRemoveAttachmentIds.value.length > 0;
    const url =
        props.isEditing && props.expense
            ? route('expenses.update', props.expense.id)
            : route('expenses.store');

    if (hasNewReceipts || hasRemovals) {
        const payload = buildFormData(parsed.data);
        if (props.isEditing && props.expense) {
            payload.append('_method', 'put');
        }
        router.post(url, payload, { ...visitOptions, forceFormData: true });
        return;
    }

    const data: Record<string, string | number> = {
        date: parsed.data.date,
        category_account_id: parsed.data.category_account_id,
        description: parsed.data.description ?? '',
        amount_excl_vat_cents: Math.round(parsed.data.amount_excl_vat * 100),
        vat_rate: parsed.data.vat_rate,
        vat_amount_cents: Math.round(parsed.data.vat_amount * 100),
        paid_from_banking_account_id: parsed.data.paid_from_banking_account_id,
        reference: parsed.data.reference ?? '',
        notes: parsed.data.notes ?? '',
    };
    if (parsed.data.supplier_id > 0) {
        data.supplier_id = parsed.data.supplier_id;
    } else {
        data.supplier = parsed.data.supplier_custom?.trim() ?? '';
    }
    if (isHomeOffice.value) {
        data.office_percentage = parsed.data.office_percentage ?? 0;
    }
    if (isTravel.value) {
        data.distance_km = parsed.data.distance_km ?? 0;
        data.rate_per_km = parsed.data.rate_per_km ?? props.sars_rate_per_km;
    }
    if (props.isEditing && props.expense) {
        data._method = 'put';
    }

    router.post(url, data, visitOptions);
};
</script>

<template>
    <AppLayout
        :title="props.isEditing ? 'Edit Expense' : 'New Expense'"
        :breadcrumbs="[
            { label: 'Money Out' },
            { label: 'Expenses', href: route('expenses.index') },
            { label: props.isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader :title="props.isEditing ? 'Edit Expense' : 'Create Expense'" />

        <AppCard v-if="!hasCategories" class="mt-5">
            <p class="text-sm text-slate-700">Add at least one active expense category in your chart of accounts before recording expenses.</p>
            <AppButton variant="primary" class="mt-3" @click="router.visit(route('accounting.accounts.index'))">Chart of accounts</AppButton>
        </AppCard>

        <AppCard v-else class="mt-5">
            <div
                v-if="visibleErrors.length"
                class="mb-5 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
                role="alert"
            >
                <p class="font-medium">Could not save expense.</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    <li v-for="(error, index) in visibleErrors" :key="index">{{ error }}</li>
                </ul>
            </div>

            <div class="mb-5">
                <div class="mb-1 flex items-center justify-between gap-3">
                    <p class="text-xs font-medium text-slate-500">Receipts</p>
                    <button
                        v-if="receiptFiles.length"
                        type="button"
                        class="text-xs font-medium text-slate-500 hover:text-slate-800"
                        @click="clearReceipts"
                    >
                        Remove all
                    </button>
                </div>
                <label
                    class="flex min-h-16 cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-center transition hover:border-slate-400 hover:bg-slate-100"
                >
                    <Upload class="h-4 w-4 shrink-0 text-slate-500" />
                    <span class="text-sm font-medium text-slate-800">
                        {{ receiptFiles.length || visibleExistingAttachments.length ? 'Add more' : 'Upload receipts' }}
                    </span>
                    <span class="hidden text-xs text-slate-500 sm:inline">Photos or PDFs</span>
                    <input type="file" accept="image/*,.pdf" multiple class="hidden" @change="onReceiptChange">
                </label>

                <div
                    v-if="aiEnabled && totalReceiptCount >= 2"
                    class="mt-2 flex flex-wrap items-center gap-2"
                >
                    <AppButton
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="scanReceiptLoading"
                        @click="scanAllReceipts"
                    >
                        <ScanLine class="mr-1.5 h-4 w-4" />
                        {{ scanningKey === 'all' ? 'AI Scanning all…' : 'AI Scan all' }}
                    </AppButton>
                    <p class="text-xs text-slate-500">Combines every receipt into one form fill (overwrites fields).</p>
                </div>
                <p v-if="receiptUploadSuccess" class="mt-2 text-xs text-emerald-700">
                    {{ receiptUploadSuccess }}
                </p>
                <p v-if="scanReceiptApplied" class="mt-2 text-xs text-emerald-700">
                    Applied from receipt — review the fields before saving.
                </p>
                <p v-if="scanReceiptError" class="mt-2 text-xs text-rose-700">
                    {{ scanReceiptError }}
                </p>

                <ul
                    v-if="visibleExistingAttachments.length || receiptFiles.length"
                    class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5"
                >
                    <li
                        v-for="attachment in visibleExistingAttachments"
                        :key="`existing-${attachment.id}`"
                        class="group relative"
                    >
                        <button
                            type="button"
                            class="aspect-square w-full overflow-hidden rounded-lg border border-slate-200 bg-slate-100 text-left transition hover:border-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                            :aria-label="`Preview ${attachment.name}`"
                            @click="openExistingAttachmentPreview(attachment)"
                        >
                            <img
                                v-if="isImageMime(attachment.mime_type, attachment.name)"
                                :src="attachment.url"
                                :alt="attachment.name"
                                class="h-full w-full object-cover"
                            >
                            <div
                                v-else-if="isPdfMime(attachment.mime_type, attachment.name)"
                                class="relative h-full w-full overflow-hidden bg-white"
                            >
                                <iframe
                                    :src="`${attachment.url}#toolbar=0&navpanes=0&scrollbar=0&view=FitH`"
                                    class="pointer-events-none absolute inset-0 h-[180%] w-[180%] origin-top-left scale-[0.56] border-0"
                                    tabindex="-1"
                                    :title="`Preview of ${attachment.name}`"
                                />
                                <span class="pointer-events-none absolute bottom-1 left-1 rounded bg-slate-900/75 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-white">
                                    PDF
                                </span>
                            </div>
                            <div
                                v-else
                                class="flex h-full w-full flex-col items-center justify-center gap-1 px-2 text-slate-500"
                            >
                                <Camera class="h-5 w-5" />
                                <span class="text-[10px] font-medium uppercase tracking-wide">File</span>
                            </div>
                        </button>
                        <p class="mt-1 truncate text-[11px] text-slate-600" :title="attachment.name">{{ attachment.name }}</p>
                        <button
                            v-if="aiEnabled"
                            type="button"
                            class="mt-1 inline-flex w-full items-center justify-center gap-1 rounded-md border border-slate-200 bg-white px-1.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="scanReceiptLoading"
                            :aria-label="`AI Scan ${attachment.name}`"
                            @click.stop="scanExistingAttachment(attachment)"
                        >
                            <ScanLine class="h-3 w-3 shrink-0" />
                            {{ scanningKey === `existing:${attachment.id}` ? 'AI Scanning…' : 'AI Scan' }}
                        </button>
                        <button
                            type="button"
                            class="absolute right-1 top-1 inline-flex h-6 w-6 items-center justify-center rounded-full bg-white/95 text-slate-600 shadow-sm ring-1 ring-slate-200 hover:bg-white hover:text-slate-900"
                            :aria-label="`Remove ${attachment.name}`"
                            @click.stop="removeExistingAttachment(attachment)"
                        >
                            <span class="text-sm leading-none" aria-hidden="true">×</span>
                        </button>
                    </li>
                    <li
                        v-for="(file, index) in receiptFiles"
                        :key="`new-${file.name}-${file.size}-${index}`"
                        class="group relative"
                    >
                        <button
                            type="button"
                            class="aspect-square w-full overflow-hidden rounded-lg border border-slate-200 bg-slate-100 text-left transition hover:border-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                            :aria-label="`Preview ${file.name}`"
                            :disabled="!receiptPreviewUrls[index]"
                            @click="openReceiptPreview(index)"
                        >
                            <img
                                v-if="isImageFile(file) && receiptPreviewUrls[index]"
                                :src="receiptPreviewUrls[index]"
                                :alt="file.name"
                                class="h-full w-full object-cover"
                            >
                            <div
                                v-else-if="isPdfFile(file) && receiptPreviewUrls[index]"
                                class="relative h-full w-full overflow-hidden bg-white"
                            >
                                <iframe
                                    :src="`${receiptPreviewUrls[index]}#toolbar=0&navpanes=0&scrollbar=0&view=FitH`"
                                    class="pointer-events-none absolute inset-0 h-[180%] w-[180%] origin-top-left scale-[0.56] border-0"
                                    tabindex="-1"
                                    :title="`Preview of ${file.name}`"
                                />
                                <span class="pointer-events-none absolute bottom-1 left-1 rounded bg-slate-900/75 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-white">
                                    PDF
                                </span>
                            </div>
                            <div
                                v-else
                                class="flex h-full w-full flex-col items-center justify-center gap-1 px-2 text-slate-500"
                            >
                                <Camera class="h-5 w-5" />
                                <span class="text-[10px] font-medium uppercase tracking-wide">File</span>
                            </div>
                        </button>
                        <p class="mt-1 truncate text-[11px] text-slate-600" :title="file.name">{{ file.name }}</p>
                        <button
                            v-if="aiEnabled"
                            type="button"
                            class="mt-1 inline-flex w-full items-center justify-center gap-1 rounded-md border border-slate-200 bg-white px-1.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                            :disabled="scanReceiptLoading"
                            :aria-label="`AI Scan ${file.name}`"
                            @click.stop="scanNewReceipt(index)"
                        >
                            <ScanLine class="h-3 w-3 shrink-0" />
                            {{ scanningKey === `new:${index}` ? 'AI Scanning…' : 'AI Scan' }}
                        </button>
                        <button
                            type="button"
                            class="absolute right-1 top-1 inline-flex h-6 w-6 items-center justify-center rounded-full bg-white/95 text-slate-600 shadow-sm ring-1 ring-slate-200 hover:bg-white hover:text-slate-900"
                            :aria-label="`Remove ${file.name}`"
                            @click.stop="removeReceiptAt(index)"
                        >
                            <span class="text-sm leading-none" aria-hidden="true">×</span>
                        </button>
                    </li>
                </ul>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        Date <span class="text-rose-500" aria-hidden="true">*</span>
                    </label>
                    <AppInput v-model="form.date" type="date" class="min-h-12 text-base md:min-h-0 md:text-sm" required />
                </div>
                <div class="md:col-span-2">
                    <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                        <label class="block text-xs font-medium text-slate-500">
                            Supplier <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <AppButton
                            type="button"
                            variant="secondary"
                            size="sm"
                            @click="openNewSupplierForm"
                        >
                            <Plus class="mr-1 h-3.5 w-3.5" />
                            New supplier
                        </AppButton>
                    </div>
                    <AppSelect
                        :model-value="String(form.supplier_id)"
                        :options="supplierSelectOptions"
                        @update:model-value="form.supplier_id = Number($event)"
                    />
                    <div v-if="form.supplier_id === 0" class="mt-2 space-y-2">
                        <AppInput
                            v-model="form.supplier_custom"
                            placeholder="One-off supplier name"
                            class="min-h-12 text-base md:min-h-0 md:text-sm"
                        />
                        <div v-if="canSaveAsSupplier" class="flex flex-wrap items-center gap-2">
                            <AppButton
                                type="button"
                                variant="secondary"
                                size="sm"
                                :loading="saveSupplierLoading"
                                @click="saveAsSupplier"
                            >
                                {{ saveSupplierLoading ? 'Saving…' : 'Save as supplier' }}
                            </AppButton>
                            <p class="text-xs text-slate-500">
                                Keep this vendor for future expenses (from the receipt name).
                            </p>
                        </div>
                        <p v-if="saveSupplierError" class="text-xs text-rose-700">{{ saveSupplierError }}</p>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        Category <span class="text-rose-500" aria-hidden="true">*</span>
                    </label>
                    <AppSelect
                        :model-value="form.category_account_id > 0 ? String(form.category_account_id) : ''"
                        :options="categorySelectOptions"
                        placeholder="Select category"
                        @update:model-value="form.category_account_id = Number($event) || 0"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        Amount (excl VAT) <span class="text-rose-500" aria-hidden="true">*</span>
                    </label>
                    <AppInput
                        v-model="form.amount_excl_vat"
                        type="text"
                        inputmode="decimal"
                        class="min-h-12 text-base md:min-h-0 md:text-sm"
                    />
                    <p v-if="isTravel" class="mt-1 text-xs text-amber-700">Travel uses distance × rate for the posted amount; this field is ignored when you save.</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Total (incl VAT)</label>
                    <AppInput
                        v-model="totalInclVat"
                        type="text"
                        inputmode="decimal"
                        class="min-h-12 text-base md:min-h-0 md:text-sm"
                    />
                    <p class="mt-1 text-xs text-slate-500">Enter the paid total to back-calculate amount excl VAT.</p>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Description <span class="font-normal text-slate-400">(optional)</span></label>
                    <AppInput v-model="form.description" placeholder="What was purchased?" class="min-h-12 text-base md:min-h-0 md:text-sm" />
                </div>
            </div>

            <button
                type="button"
                class="mt-4 flex w-full min-h-12 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-slate-50 text-sm font-medium text-slate-800 md:hidden"
                @click="showAdvanced = !showAdvanced"
            >
                {{ showAdvanced ? 'Hide' : 'More options' }}
                <span class="text-xs text-slate-500">(VAT, paid from, notes)</span>
            </button>

            <div :class="['mt-4 grid gap-4 md:grid-cols-2', !showAdvanced && 'max-md:hidden']">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        VAT rate <span class="text-rose-500" aria-hidden="true">*</span>
                    </label>
                    <AppSelect
                        :model-value="form.vat_rate"
                        :options="taxRateSelectOptions"
                        @update:model-value="form.vat_rate = $event as 'vat15' | 'vat0' | 'exempt' | 'no_vat'"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">VAT amount (override) <span class="font-normal text-slate-400">(optional)</span></label>
                    <AppInput v-model="form.vat_amount" type="text" inputmode="decimal" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        Paid from <span class="text-rose-500" aria-hidden="true">*</span>
                    </label>
                    <AppSelect
                        :model-value="String(form.paid_from_banking_account_id)"
                        :options="paidFromList.map((option) => ({ label: `${option.name} (${option.gl_label})`, value: String(option.id) }))"
                        @update:model-value="form.paid_from_banking_account_id = Number($event)"
                    />
                    <p class="mt-1 text-xs text-slate-500">Which bank, cash, or card account this expense was paid from (posts to its linked ledger account).</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Reference <span class="font-normal text-slate-400">(optional)</span></label>
                    <AppInput v-model="form.reference" placeholder="Invoice / order #" />
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Notes <span class="font-normal text-slate-400">(optional)</span></label>
                    <textarea v-model="form.notes" class="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                </div>
            </div>

            <div v-if="isHomeOffice" class="mt-5 rounded-md border border-slate-200 p-4">
                <p class="text-sm font-semibold text-slate-900">Home Office Details</p>
                <p class="mt-1 text-xs text-slate-500">Enter the full bill amount above. Only the office percentage is posted to the ledger.</p>
                <label class="mt-2 block text-xs font-medium text-slate-500">Office percentage: {{ form.office_percentage }}%</label>
                <input v-model.number="form.office_percentage" type="range" min="0" max="100" class="mt-2 w-full">
            </div>

            <div v-if="isTravel" class="mt-5 rounded-md border border-slate-200 p-4">
                <p class="text-sm font-semibold text-slate-900">Travel Details</p>
                <div class="mt-2 grid gap-3 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Distance (km)</label>
                        <AppInput v-model="form.distance_km" type="number" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Rate per km</label>
                        <AppInput v-model="form.rate_per_km" type="number" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Calculated deduction</label>
                        <AppInput :model-value="useFormatCurrency(travelDeduction, 'ZAR')" disabled />
                    </div>
                </div>
                <p class="mt-2 text-xs text-amber-700">Keep logbook for SARS compliance.</p>
            </div>

            <FormActions>
                <AppButton
                    variant="primary"
                    size="touch"
                    class="w-full sm:w-auto sm:min-h-0 sm:px-4 sm:py-2 sm:text-sm"
                    :loading="submitting"
                    @click="submit"
                >
                    {{ submitting ? 'Saving…' : props.isEditing ? 'Update Expense' : 'Save Expense' }}
                </AppButton>
                <AppButton
                    variant="secondary"
                    size="touch"
                    class="w-full sm:w-auto sm:min-h-0 sm:px-4 sm:py-2 sm:text-sm"
                    :disabled="submitting"
                    @click="router.visit(route('expenses.index'))"
                >
                    Cancel
                </AppButton>
            </FormActions>
        </AppCard>

        <Teleport to="body">
            <div
                v-if="previewedReceipt"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/70 p-4"
                role="dialog"
                aria-modal="true"
                :aria-label="`Preview ${previewedReceipt.name}`"
                @click.self="closeReceiptPreview"
            >
                <div class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                        <p class="truncate text-sm font-medium text-slate-900" :title="previewedReceipt.name">
                            {{ previewedReceipt.name }}
                        </p>
                        <button
                            type="button"
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                            aria-label="Close preview"
                            @click="closeReceiptPreview"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="flex min-h-0 flex-1 items-center justify-center bg-slate-50 p-3 sm:p-4">
                        <img
                            v-if="previewedReceipt.isImage"
                            :src="previewedReceipt.url"
                            :alt="previewedReceipt.name"
                            class="max-h-[75vh] max-w-full rounded object-contain"
                        >
                        <iframe
                            v-else-if="previewedReceipt.isPdf"
                            :src="previewedReceipt.url"
                            class="h-[75vh] w-full rounded border-0 bg-white"
                            :title="previewedReceipt.name"
                        />
                        <p v-else class="text-sm text-slate-500">Preview is not available for this file type.</p>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
