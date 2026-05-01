<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppCard from '@/Components/AppCard.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';

type EstimateDetail = {
    id: number;
    number: string;
    client_id: number;
    client_name: string;
    issue_date: string;
    expiry_date: string;
    subtotal_cents: number;
    vat_amount_cents: number;
    total_cents: number;
    currency: string;
    status: 'draft' | 'sent' | 'accepted' | 'declined' | 'expired' | 'converted';
    notes: string | null;
    terms: string | null;
    line_items: Array<{
        description: string;
        quantity: number;
        unit_price_cents: number;
        vat_rate: number;
    }>;
    converted_invoice_id: number | null;
};

const props = defineProps<{
    estimate: EstimateDetail;
    /** When false, VAT rows are hidden in totals (company not VAT-registered / no default rate). */
    charges_vat: boolean;
    can: {
        delete: boolean;
    };
    convert_defaults: {
        invoice_due_date: string;
        invoice_footer: string;
        invoice_notes: string;
    };
}>();

const convertDrawerOpen = ref(false);
const convertForm = ref({
    invoice_due_date: props.convert_defaults.invoice_due_date,
    invoice_footer: props.convert_defaults.invoice_footer,
    invoice_notes: props.convert_defaults.invoice_notes,
});

const currency = (cents: number) => useFormatCurrency(cents / 100, props.estimate.currency || 'ZAR');

const badgeVariant = () => {
    if (props.estimate.status === 'accepted') return 'success';
    if (props.estimate.status === 'declined') return 'danger';
    if (props.estimate.status === 'expired') return 'danger';
    if (props.estimate.status === 'converted') return 'neutral';
    return 'info';
};

const submitConvert = () => {
    router.post(route('invoicing.estimates.convert', props.estimate.id), convertForm.value, {
        onSuccess: () => {
            convertDrawerOpen.value = false;
        },
    });
};

const downloadPdf = () => {
    window.location.assign(route('invoicing.estimates.pdf.download', props.estimate.id));
};

const deleteEstimate = () => {
    if (!window.confirm('Permanently delete this estimate? This cannot be undone.')) {
        return;
    }
    router.delete(route('invoicing.estimates.destroy', props.estimate.id));
};
</script>

<template>
    <AppLayout
        :title="estimate.number"
        :breadcrumbs="[
            { label: 'Money In' },
            { label: 'Estimates', href: route('invoicing.estimates.index') },
            { label: estimate.number },
        ]"
    >
        <Head :title="estimate.number" />

        <PageHeader :title="estimate.number">
            <template #actions>
                <div class="flex gap-2">
                    <AppButton variant="primary" @click="router.visit(route('invoicing.estimates.edit', estimate.id))">Edit estimate</AppButton>
                    <AppButton variant="primary" @click="downloadPdf">Download PDF</AppButton>
                    <AppButton v-if="estimate.status === 'draft'" variant="primary" @click="router.post(route('invoicing.estimates.send', estimate.id))">Send estimate</AppButton>
                    <AppButton v-if="estimate.status === 'draft'" variant="primary" @click="router.post(route('invoicing.estimates.mark-sent', estimate.id))">Mark as sent</AppButton>
                    <AppButton v-if="estimate.status === 'sent'" variant="primary" @click="router.post(route('invoicing.estimates.accept', estimate.id))">Mark accepted</AppButton>
                    <AppButton v-if="estimate.status === 'sent'" variant="primary" @click="router.post(route('invoicing.estimates.decline', estimate.id))">Mark declined</AppButton>
                    <AppButton
                        v-if="['accepted', 'sent'].includes(estimate.status)"
                        variant="primary"
                        @click="convertDrawerOpen = true"
                    >
                        Convert to invoice
                    </AppButton>
                    <AppButton
                        v-if="estimate.status === 'converted' && estimate.converted_invoice_id"
                        variant="primary"
                        @click="router.visit(route('invoicing.invoices.show', estimate.converted_invoice_id))"
                    >
                        View invoice
                    </AppButton>
                    <AppButton
                        v-if="can.delete"
                        variant="primary"
                        @click="deleteEstimate"
                    >
                        Delete
                    </AppButton>
                </div>
            </template>
        </PageHeader>

        <div class="grid gap-6 xl:grid-cols-3">
            <section class="xl:col-span-2">
                <AppCard class="space-y-4">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Client</p>
                            <p class="text-sm font-medium text-slate-900">{{ estimate.client_name }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Status</p>
                            <AppBadge :variant="badgeVariant()">{{ estimate.status }}</AppBadge>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Issued</p>
                            <p class="text-sm text-slate-900">{{ estimate.issue_date }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-500">Valid until</p>
                            <p class="text-sm text-slate-900">{{ estimate.expiry_date }}</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">Description</th>
                                    <th class="px-3 py-2 text-left">Qty</th>
                                    <th class="px-3 py-2 text-left">Unit</th>
                                    <th v-if="charges_vat" class="px-3 py-2 text-left">VAT</th>
                                    <th class="px-3 py-2 text-left">Line total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(line, idx) in estimate.line_items" :key="idx">
                                    <td class="px-3 py-2">{{ line.description }}</td>
                                    <td class="px-3 py-2">{{ line.quantity }}</td>
                                    <td class="px-3 py-2">{{ currency(line.unit_price_cents) }}</td>
                                    <td v-if="charges_vat" class="px-3 py-2">{{ Math.round(line.vat_rate * 100) }}%</td>
                                    <td class="px-3 py-2">{{ currency(Math.round(line.quantity * line.unit_price_cents * (1 + line.vat_rate))) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="ml-auto w-full max-w-sm space-y-2 text-sm">
                        <div class="flex items-center justify-between"><span class="text-slate-500">Subtotal</span><span>{{ currency(estimate.subtotal_cents) }}</span></div>
                        <div v-if="charges_vat" class="flex items-center justify-between"><span class="text-slate-500">VAT</span><span>{{ currency(estimate.vat_amount_cents) }}</span></div>
                        <div class="flex items-center justify-between border-t border-slate-200 pt-2 font-semibold"><span>Total</span><span>{{ currency(estimate.total_cents) }}</span></div>
                    </div>

                    <div v-if="estimate.notes" class="rounded-md border border-slate-200 p-3 text-sm text-slate-700">
                        <p class="mb-1 text-xs uppercase tracking-wide text-slate-500">Notes</p>
                        {{ estimate.notes }}
                    </div>
                    <div v-if="estimate.terms" class="rounded-md border border-slate-200 p-3 text-sm text-slate-700">
                        <p class="mb-1 text-xs uppercase tracking-wide text-slate-500">Terms</p>
                        {{ estimate.terms }}
                    </div>
                </AppCard>
            </section>

            <aside class="space-y-4">
                <AppCard>
                    <h3 class="text-base font-semibold text-slate-900">Estimate total</h3>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ currency(estimate.total_cents) }}</p>
                    <p v-if="charges_vat" class="mt-2 text-xs text-slate-500">Incl VAT estimate</p>
                </AppCard>
                <AppCard>
                    <h3 class="text-base font-semibold text-slate-900">Next actions</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-4 text-sm text-slate-600">
                        <li>Send estimate by email</li>
                        <li>Follow up before expiry</li>
                        <li>Convert accepted estimate to invoice</li>
                    </ul>
                </AppCard>
            </aside>
        </div>

        <div v-if="convertDrawerOpen" class="fixed inset-0 z-[80] bg-black/40" @click="convertDrawerOpen = false" />
        <aside
            :class="[
                'fixed inset-y-0 right-0 z-[90] w-full max-w-md transform bg-white shadow-xl transition-transform',
                convertDrawerOpen ? 'translate-x-0' : 'translate-x-full',
            ]"
        >
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h3 class="text-lg font-semibold text-slate-900">Convert to Invoice</h3>
                <button class="rounded p-1 hover:bg-slate-100" @click="convertDrawerOpen = false">✕</button>
            </div>
            <div class="space-y-4 px-5 py-4 text-sm">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Invoice due date</label>
                    <AppInput v-model="convertForm.invoice_due_date" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Invoice notes</label>
                    <textarea
                        v-model="convertForm.invoice_notes"
                        class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Invoice footer / terms</label>
                    <textarea
                        v-model="convertForm.invoice_footer"
                        class="min-h-24 w-full rounded-md border border-slate-300 px-3 py-2"
                    />
                </div>
                <div class="flex justify-end">
                    <AppButton variant="primary" @click="submitConvert">Create invoice</AppButton>
                </div>
            </div>
        </aside>
    </AppLayout>
</template>
