<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppButton from '@/Components/AppButton.vue';
import AppCard from '@/Components/AppCard.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { Download, ExternalLink } from 'lucide-vue-next';

type Issuer = {
    name: string;
    address: string | null;
    email: string | null;
    phone: string | null;
    website: string | null;
    registration_number: string | null;
    vat_number: string | null;
};

const props = defineProps<{
    issuer: Issuer;
    charges_vat: boolean;
    invoice: {
        number: string;
        status: string;
        reference: string | null;
        issue_date: string | null;
        due_date: string | null;
        currency: string;
        subtotal_cents: number;
        vat_amount_cents: number;
        total_cents: number;
        amount_paid_cents: number;
        amount_due_cents: number;
        notes: string | null;
        footer: string | null;
        client: { name: string | null; email: string | null };
        line_items: Array<{
            description: string;
            quantity: number;
            unit_price_cents: number;
            vat_rate: number;
            vat_amount_cents: number;
            total_cents: number;
        }>;
    };
    online_payment_providers: string[];
    pdf_url: string;
    checkout_url: string;
    flash_online_payment?: string | null;
    flash_error?: string | null;
}>();

const invoiceCurrency = computed(() => props.invoice.currency || 'ZAR');
const formatCents = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, invoiceCurrency.value);

const documentTitle = computed(() => (props.charges_vat ? 'Tax invoice' : 'Invoice'));

const paymentFlashSuccess = computed(() => props.flash_online_payment === 'success');
const paymentFlashCancelled = computed(() => props.flash_online_payment === 'cancelled');

const startCheckout = (provider: string) => {
    router.post(props.checkout_url, { provider }, { preserveScroll: true });
};
</script>

<template>
    <div class="min-h-screen bg-slate-100">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-4">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-slate-900">{{ issuer.name }}</p>
                    <p class="truncate text-xs text-slate-500">{{ documentTitle }} {{ invoice.number }}</p>
                </div>
                <a
                    :href="pdf_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50"
                >
                    <Download class="h-4 w-4" aria-hidden="true" />
                    PDF
                </a>
            </div>
        </header>

        <main class="mx-auto max-w-3xl space-y-4 px-4 py-6">
            <div
                v-if="flash_error"
                class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
            >
                {{ flash_error }}
            </div>
            <div
                v-if="paymentFlashSuccess"
                class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
            >
                Payment completed — thank you. If your balance still shows an amount due, refresh in a moment while we
                confirm with your bank.
            </div>
            <div
                v-if="paymentFlashCancelled"
                class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
            >
                Checkout was cancelled. You can try again when ready.
            </div>

            <AppCard class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500">Bill to</p>
                        <p class="mt-1 text-base font-medium text-slate-900">{{ invoice.client.name || '—' }}</p>
                        <p v-if="invoice.client.email" class="text-sm text-slate-600">{{ invoice.client.email }}</p>
                    </div>
                    <div class="text-right text-sm text-slate-600">
                        <p v-if="invoice.issue_date"><span class="text-slate-500">Issued</span> {{ invoice.issue_date }}</p>
                        <p v-if="invoice.due_date"><span class="text-slate-500">Due</span> {{ invoice.due_date }}</p>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-md border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-3 py-2 text-left">Description</th>
                                <th class="px-3 py-2 text-right">Qty</th>
                                <th class="px-3 py-2 text-right">Price</th>
                                <th v-if="charges_vat" class="px-3 py-2 text-right">VAT</th>
                                <th class="px-3 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="(line, idx) in invoice.line_items" :key="idx">
                                <td class="px-3 py-2 text-slate-800">{{ line.description }}</td>
                                <td class="px-3 py-2 text-right text-slate-700">{{ line.quantity }}</td>
                                <td class="px-3 py-2 text-right text-slate-700">{{ formatCents(line.unit_price_cents) }}</td>
                                <td v-if="charges_vat" class="px-3 py-2 text-right text-slate-700">
                                    {{ formatCents(line.vat_amount_cents) }}
                                </td>
                                <td class="px-3 py-2 text-right font-medium text-slate-900">{{ formatCents(line.total_cents) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="ml-auto w-full max-w-xs space-y-1 text-sm">
                    <div class="flex justify-between text-slate-600">
                        <span>Subtotal</span><span>{{ formatCents(invoice.subtotal_cents) }}</span>
                    </div>
                    <div v-if="charges_vat" class="flex justify-between text-slate-600">
                        <span>VAT</span><span>{{ formatCents(invoice.vat_amount_cents) }}</span>
                    </div>
                    <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold text-slate-900">
                        <span>Total</span><span>{{ formatCents(invoice.total_cents) }}</span>
                    </div>
                    <div v-if="invoice.amount_paid_cents > 0" class="flex justify-between text-slate-600">
                        <span>Paid</span><span>{{ formatCents(invoice.amount_paid_cents) }}</span>
                    </div>
                    <div class="flex justify-between text-base font-semibold text-slate-900">
                        <span>Amount due</span><span>{{ formatCents(invoice.amount_due_cents) }}</span>
                    </div>
                </div>

                <div v-if="invoice.notes" class="rounded-md border border-slate-100 bg-slate-50/80 p-3 text-sm text-slate-700">
                    {{ invoice.notes }}
                </div>
            </AppCard>

            <AppCard v-if="invoice.amount_due_cents > 0 && invoice.status !== 'paid'" class="space-y-3">
                <h2 class="text-base font-semibold text-slate-900">Pay online</h2>
                <p class="text-sm text-slate-600">Choose a payment method. You will be redirected to a secure checkout page.</p>
                <div class="flex flex-wrap gap-2">
                    <AppButton
                        v-if="online_payment_providers.includes('stripe')"
                        variant="primary"
                        type="button"
                        @click="startCheckout('stripe')"
                    >
                        Pay with Stripe
                    </AppButton>
                    <AppButton
                        v-if="online_payment_providers.includes('payfast')"
                        variant="primary"
                        type="button"
                        @click="startCheckout('payfast')"
                    >
                        Pay with PayFast
                    </AppButton>
                </div>
                <p v-if="!online_payment_providers.length" class="text-sm text-slate-500">
                    Online payment is not available for this invoice. Use the PDF link above for your records or contact the business.
                </p>
            </AppCard>

            <AppCard v-else-if="invoice.status === 'paid'" class="border-emerald-200 bg-emerald-50/50">
                <p class="text-sm font-medium text-emerald-900">This invoice is paid. Thank you.</p>
            </AppCard>

            <p class="text-center text-xs text-slate-500">
                Secured by {{ issuer.name }}
                <a
                    v-if="issuer.website"
                    :href="issuer.website"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="ml-1 inline-flex items-center gap-0.5 text-brand-600 hover:underline"
                >
                    Website
                    <ExternalLink class="h-3 w-3" aria-hidden="true" />
                </a>
            </p>
        </main>
    </div>
</template>
