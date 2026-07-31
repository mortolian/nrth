<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import { FileText, ScanLine, Upload, X } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppPhoneInput from '@/Components/AppPhoneInput.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useToast } from '@/Composables/useToast';

const props = defineProps<{
    isEditing: boolean;
    return_to?: string | null;
    prefill?: {
        name?: string | null;
    } | null;
    supplier: null | {
        id: number;
        name: string;
        contact_name: string | null;
        email: string | null;
        phone: string | null;
        vat_number: string | null;
        registration_number: string | null;
        address: {
            street?: string;
            city?: string;
            province?: string;
            postal_code?: string;
            country?: string;
        } | null;
        notes: string | null;
        is_active: boolean;
    };
}>();

const page = usePage();
const toast = useToast();
const saving = ref(false);
const aiEnabled = computed(() => Boolean(page.props.ai_enabled));
const scanFile = ref<File | null>(null);
const scanPreviewUrl = ref<string | null>(null);
const scanPreviewOpen = ref(false);
const scanLoading = ref(false);
const scanError = ref<string | null>(null);
const scanApplied = ref(false);
const { fieldErrors, setFromZod, setFromServer, clear, clearField } = useFieldErrors();

const isImageFile = (file: File) =>
    file.type.startsWith('image/') || /\.(jpe?g|png|gif|webp|heic)$/i.test(file.name);
const isPdfFile = (file: File) =>
    file.type === 'application/pdf' || /\.pdf$/i.test(file.name);

const revokeScanPreview = () => {
    if (scanPreviewUrl.value) {
        URL.revokeObjectURL(scanPreviewUrl.value);
        scanPreviewUrl.value = null;
    }
};

const setScanFile = (file: File | null) => {
    revokeScanPreview();
    scanPreviewOpen.value = false;
    scanFile.value = file;
    scanError.value = null;
    scanApplied.value = false;
    if (file && (isImageFile(file) || isPdfFile(file))) {
        scanPreviewUrl.value = URL.createObjectURL(file);
    }
};

const openScanPreview = () => {
    if (!scanFile.value || !scanPreviewUrl.value) {
        return;
    }
    scanPreviewOpen.value = true;
};

const closeScanPreview = () => {
    scanPreviewOpen.value = false;
};

const previewedDocument = computed(() => {
    if (!scanPreviewOpen.value || !scanFile.value || !scanPreviewUrl.value) {
        return null;
    }

    return {
        name: scanFile.value.name,
        url: scanPreviewUrl.value,
        isImage: isImageFile(scanFile.value),
        isPdf: isPdfFile(scanFile.value),
    };
});

watch(scanPreviewOpen, (open, _prev, onCleanup) => {
    if (!open) {
        return;
    }

    const onKeydown = (event: KeyboardEvent) => {
        if (event.key === 'Escape') {
            closeScanPreview();
        }
    };
    window.addEventListener('keydown', onKeydown);
    onCleanup(() => window.removeEventListener('keydown', onKeydown));
});

onUnmounted(() => {
    scanPreviewOpen.value = false;
    revokeScanPreview();
});

const { values, setFieldValue: setVeeFieldValue } = useForm({
    initialValues: {
        name: props.supplier?.name ?? props.prefill?.name ?? '',
        contact_name: props.supplier?.contact_name ?? '',
        email: props.supplier?.email ?? '',
        phone: props.supplier?.phone ?? '',
        vat_number: props.supplier?.vat_number ?? '',
        registration_number: props.supplier?.registration_number ?? '',
        address: {
            street: props.supplier?.address?.street ?? '',
            city: props.supplier?.address?.city ?? '',
            province: props.supplier?.address?.province ?? '',
            postal_code: props.supplier?.address?.postal_code ?? '',
            country: props.supplier?.address?.country ?? 'South Africa',
        },
        notes: props.supplier?.notes ?? '',
        is_active: props.supplier?.is_active ?? true,
    },
});

const setFieldValue = (path: string, value: unknown) => {
    setVeeFieldValue(path, value);
    clearField(path);
};

const formValues = computed<Record<string, any>>(() => ((values as any)?.value ?? values) as Record<string, any>);

const schema = z.object({
    name: z.string().trim().min(1, 'Supplier name is required'),
    contact_name: z.string().optional(),
    email: z.string().email('Invalid email').or(z.literal('')),
    phone: z.string().optional(),
    vat_number: z.string().regex(/^$|^4\d{9}$/, 'SA VAT must be 10 digits starting with 4'),
    registration_number: z.string().optional(),
    address: z.object({
        street: z.string().optional(),
        city: z.string().optional(),
        province: z.string().optional(),
        postal_code: z.string().optional(),
        country: z.string().optional(),
    }),
    notes: z.string().optional(),
    is_active: z.boolean(),
});

type ScanPayload = {
    name?: string | null;
    contact_name?: string | null;
    email?: string | null;
    phone?: string | null;
    vat_number?: string | null;
    registration_number?: string | null;
    address?: {
        street?: string | null;
        city?: string | null;
        province?: string | null;
        postal_code?: string | null;
        country?: string | null;
    } | null;
    notes?: string | null;
};

const onScanFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    setScanFile(input.files?.[0] ?? null);
    input.value = '';
};

const scanDropActive = ref(false);

const isAcceptedScanFile = (file: File) => isImageFile(file) || isPdfFile(file);

const onScanDragEnter = (event: DragEvent) => {
    event.preventDefault();
    scanDropActive.value = true;
};

const onScanDragOver = (event: DragEvent) => {
    event.preventDefault();
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'copy';
    }
    scanDropActive.value = true;
};

const onScanDragLeave = (event: DragEvent) => {
    event.preventDefault();
    const current = event.currentTarget as HTMLElement | null;
    const related = event.relatedTarget as Node | null;
    if (current && related && current.contains(related)) {
        return;
    }
    scanDropActive.value = false;
};

const onScanDrop = (event: DragEvent) => {
    event.preventDefault();
    scanDropActive.value = false;
    const file = event.dataTransfer?.files?.[0] ?? null;
    if (!file) {
        return;
    }
    if (!isAcceptedScanFile(file)) {
        scanError.value = 'Upload an image or PDF.';
        scanApplied.value = false;
        return;
    }
    setScanFile(file);
};

const clearScanFile = () => {
    setScanFile(null);
};

const applyScanPayload = (data: ScanPayload) => {
    if (data.name?.trim()) setFieldValue('name', data.name.trim());
    if (data.contact_name != null) setFieldValue('contact_name', data.contact_name);
    if (data.email != null) setFieldValue('email', data.email);
    if (data.phone != null) setFieldValue('phone', data.phone);
    if (data.vat_number != null) setFieldValue('vat_number', data.vat_number);
    if (data.registration_number != null) setFieldValue('registration_number', data.registration_number);
    if (data.notes != null) setFieldValue('notes', data.notes);
    if (data.address) {
        if (data.address.street != null) setFieldValue('address.street', data.address.street);
        if (data.address.city != null) setFieldValue('address.city', data.address.city);
        if (data.address.province != null) setFieldValue('address.province', data.address.province);
        if (data.address.postal_code != null) setFieldValue('address.postal_code', data.address.postal_code);
        if (data.address.country != null) setFieldValue('address.country', data.address.country);
    }
};

const runAiScan = async () => {
    if (!aiEnabled.value || scanLoading.value || !scanFile.value) {
        return;
    }

    scanLoading.value = true;
    scanError.value = null;
    scanApplied.value = false;

    try {
        const token = page.props.csrf_token as string | undefined;
        if (!token) {
            throw new Error('Unable to scan: missing security token. Refresh the page and try again.');
        }

        const body = new FormData();
        body.append('document', scanFile.value);

        let res: Response;
        try {
            res = await fetch(route('suppliers.parse-document'), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body,
            });
        } catch {
            throw new Error('Could not reach the scanning service (network). Check the server can reach the AI provider.');
        }

        const payload = (await res.json().catch(() => null)) as {
            message?: string;
            errors?: Record<string, string[]>;
            data?: ScanPayload;
        } | null;

        if (!res.ok) {
            const firstError = payload?.errors
                ? Object.values(payload.errors).flat()[0]
                : null;
            throw new Error(firstError || payload?.message || `Scan failed (HTTP ${res.status}).`);
        }

        if (!payload?.data) {
            throw new Error('Could not read supplier details from this document.');
        }

        applyScanPayload(payload.data);
        scanApplied.value = true;
        toast.success('Applied from document — review before saving.');
    } catch (error) {
        scanError.value =
            error instanceof Error
                ? error.message
                : 'Could not reach the scanning service. Try again.';
    } finally {
        scanLoading.value = false;
    }
};

const submit = () => {
    if (saving.value) return;

    const result = schema.safeParse(formValues.value);
    if (!result.success) {
        setFromZod(result.error);
        return;
    }

    clear();

    const visitOptions = {
        onStart: () => {
            saving.value = true;
        },
        onSuccess: () => {
            toast.success(props.isEditing ? 'Supplier saved.' : 'Supplier created.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
            if (!Object.keys(errors).length) {
                toast.error('Could not save this supplier.');
            }
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (props.isEditing && props.supplier) {
        router.put(route('suppliers.update', props.supplier.id), result.data, visitOptions);
        return;
    }
    const payload = props.return_to ? { ...result.data, return: props.return_to } : result.data;
    router.post(route('suppliers.store'), payload, visitOptions);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit Supplier' : 'New Supplier'"
        :breadcrumbs="[
            { label: 'Money Out' },
            { label: 'Suppliers', href: route('suppliers.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader :title="isEditing ? 'Edit Supplier' : 'Create Supplier'" />

        <AppCard class="mt-5">
            <form @submit.prevent="submit">
                <div
                    v-if="aiEnabled"
                    class="mb-5 rounded-lg border border-slate-200 bg-slate-50 p-4"
                >
                    <p class="text-sm font-semibold text-slate-900">Fill from document</p>
                    <p class="mt-1 text-xs text-slate-500">
                        Upload a tax invoice, letterhead, or statement and AI Scan will fill supplier details.
                    </p>
                    <label
                        class="mt-3 flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed px-4 py-3 text-center transition"
                        :class="scanDropActive
                            ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/20'
                            : 'border-slate-300 bg-white hover:border-slate-400 hover:bg-slate-50'"
                        @dragenter="onScanDragEnter"
                        @dragover="onScanDragOver"
                        @dragleave="onScanDragLeave"
                        @drop="onScanDrop"
                    >
                        <Upload class="h-4 w-4 shrink-0 text-slate-500" />
                        <span class="text-sm font-medium text-slate-800">
                            {{ scanDropActive ? 'Drop to upload' : scanFile ? 'Replace document' : 'Upload document' }}
                        </span>
                        <span class="hidden text-xs text-slate-500 sm:inline">Photo or PDF — click or drag</span>
                        <input type="file" accept="image/*,.pdf" class="hidden" @change="onScanFileChange">
                    </label>
                    <div v-if="scanFile" class="mt-3 flex flex-wrap items-start gap-3">
                        <button
                            type="button"
                            class="relative h-20 w-20 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-white text-left transition hover:border-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                            :title="`Preview ${scanFile.name}`"
                            :aria-label="`Preview ${scanFile.name}`"
                            :disabled="!scanPreviewUrl"
                            @click="openScanPreview"
                        >
                            <img
                                v-if="isImageFile(scanFile) && scanPreviewUrl"
                                :src="scanPreviewUrl"
                                :alt="scanFile.name"
                                class="h-full w-full object-cover"
                            >
                            <div
                                v-else-if="isPdfFile(scanFile) && scanPreviewUrl"
                                class="relative h-full w-full overflow-hidden bg-white"
                            >
                                <iframe
                                    :src="`${scanPreviewUrl}#toolbar=0&navpanes=0&scrollbar=0&view=FitH`"
                                    class="pointer-events-none absolute inset-0 h-[180%] w-[180%] origin-top-left scale-[0.56] border-0"
                                    tabindex="-1"
                                    :title="`Preview of ${scanFile.name}`"
                                />
                                <span class="pointer-events-none absolute bottom-1 left-1 rounded bg-slate-900/75 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-white">
                                    PDF
                                </span>
                            </div>
                            <div
                                v-else
                                class="flex h-full w-full flex-col items-center justify-center gap-1 px-2 text-slate-500"
                            >
                                <FileText class="h-5 w-5" />
                                <span class="text-[10px] font-medium uppercase tracking-wide">File</span>
                            </div>
                        </button>
                        <div class="min-w-0 flex-1 space-y-2">
                            <p class="truncate text-xs text-slate-600" :title="scanFile.name">{{ scanFile.name }}</p>
                            <div class="flex flex-wrap items-center gap-2">
                                <AppButton
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    :disabled="scanLoading"
                                    @click="runAiScan"
                                >
                                    <ScanLine class="mr-1.5 h-4 w-4" />
                                    {{ scanLoading ? 'AI Scanning…' : 'AI Scan' }}
                                </AppButton>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-800"
                                    @click="clearScanFile"
                                >
                                    <X class="h-3 w-3" />
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>
                    <p v-if="scanApplied" class="mt-2 text-xs text-emerald-700">
                        Applied from document — review the fields before saving.
                    </p>
                    <p v-if="scanError" class="mt-2 text-xs text-rose-700">
                        {{ scanError }}
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">
                            Supplier name <span class="text-rose-500" aria-hidden="true">*</span>
                        </label>
                        <AppInput :model-value="values.name" required @update:model-value="setFieldValue('name', $event)" />
                        <p v-if="fieldErrors.name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Contact name <span class="font-normal text-slate-400">(optional)</span></label>
                        <AppInput :model-value="values.contact_name" @update:model-value="setFieldValue('contact_name', $event)" />
                        <p v-if="fieldErrors.contact_name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.contact_name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Email <span class="font-normal text-slate-400">(optional)</span></label>
                        <AppInput :model-value="values.email" type="email" @update:model-value="setFieldValue('email', $event)" />
                        <p v-if="fieldErrors.email" class="mt-1 text-xs text-rose-600">{{ fieldErrors.email }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Phone <span class="font-normal text-slate-400">(optional)</span></label>
                        <AppPhoneInput :model-value="values.phone" @update:model-value="setFieldValue('phone', $event ?? '')" />
                        <p v-if="fieldErrors.phone" class="mt-1 text-xs text-rose-600">{{ fieldErrors.phone }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">VAT number <span class="font-normal text-slate-400">(optional)</span></label>
                        <AppInput :model-value="values.vat_number" placeholder="4XXXXXXXXX" @update:model-value="setFieldValue('vat_number', $event)" />
                        <p v-if="fieldErrors.vat_number" class="mt-1 text-xs text-rose-600">{{ fieldErrors.vat_number }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Registration number <span class="font-normal text-slate-400">(optional)</span></label>
                        <AppInput :model-value="values.registration_number" @update:model-value="setFieldValue('registration_number', $event)" />
                        <p v-if="fieldErrors.registration_number" class="mt-1 text-xs text-rose-600">{{ fieldErrors.registration_number }}</p>
                    </div>
                    <div class="md:col-span-2">
                        <h3 class="mb-2 text-sm font-semibold text-slate-800">Address <span class="font-normal text-slate-400">(optional)</span></h3>
                        <div class="grid gap-3 md:grid-cols-2">
                            <AppInput :model-value="values.address.street" placeholder="Street" @update:model-value="setFieldValue('address.street', $event)" />
                            <AppInput :model-value="values.address.city" placeholder="City" @update:model-value="setFieldValue('address.city', $event)" />
                            <AppInput :model-value="values.address.province" placeholder="Province" @update:model-value="setFieldValue('address.province', $event)" />
                            <AppInput :model-value="values.address.postal_code" placeholder="Postal code" @update:model-value="setFieldValue('address.postal_code', $event)" />
                            <AppInput :model-value="values.address.country" placeholder="Country" @update:model-value="setFieldValue('address.country', $event)" />
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Notes <span class="font-normal text-slate-400">(optional)</span></label>
                        <textarea
                            :value="values.notes"
                            class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                            @input="setFieldValue('notes', ($event.target as HTMLTextAreaElement).value)"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                        <AppSelect
                            :model-value="values.is_active ? 'active' : 'inactive'"
                            :options="[{ label: 'Active', value: 'active' }, { label: 'Inactive', value: 'inactive' }]"
                            @update:model-value="setFieldValue('is_active', $event === 'active')"
                        />
                    </div>
                </div>
                <FormActions>
                    <AppButton type="submit" variant="primary" :loading="saving">
                        {{
                            saving
                                ? 'Saving…'
                                : isEditing
                                    ? 'Update Supplier'
                                    : 'Create Supplier'
                        }}
                    </AppButton>
                    <AppButton type="button" variant="secondary" @click="router.visit(route('suppliers.index'))">
                        Cancel
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>

        <Teleport to="body">
            <div
                v-if="previewedDocument"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-black/70 p-4"
                role="dialog"
                aria-modal="true"
                :aria-label="`Preview ${previewedDocument.name}`"
                @click.self="closeScanPreview"
            >
                <div class="relative flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                        <p class="truncate text-sm font-medium text-slate-900" :title="previewedDocument.name">
                            {{ previewedDocument.name }}
                        </p>
                        <button
                            type="button"
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900"
                            aria-label="Close preview"
                            @click="closeScanPreview"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                    <div class="flex min-h-0 flex-1 items-center justify-center bg-slate-50 p-3 sm:p-4">
                        <img
                            v-if="previewedDocument.isImage"
                            :src="previewedDocument.url"
                            :alt="previewedDocument.name"
                            class="max-h-[75vh] max-w-full rounded object-contain"
                        >
                        <iframe
                            v-else-if="previewedDocument.isPdf"
                            :src="previewedDocument.url"
                            class="h-[75vh] w-full rounded border-0 bg-white"
                            :title="previewedDocument.name"
                        />
                        <p v-else class="text-sm text-slate-500">Preview is not available for this file type.</p>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
