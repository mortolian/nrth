<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import FeatureShell from '@/Components/FeatureShell.vue';
import { useTaxTabs } from '@/Composables/useFeatureTabs';
import { useFormatCurrency } from '@/composables/useFormatCurrency';

const taxTabs = useTaxTabs();

const props = defineProps<{
    tax_year: {
        label: string;
        start: string;
        end: string;
    };
    periods: Array<{
        id: number;
        label: string;
        period_start: string | null;
        period_end: string | null;
        due_date: string | null;
        status: 'upcoming' | 'paid' | 'overdue';
        estimated_income: number;
        suggested_payment: number;
        safe_harbour: number;
    }>;
    income_estimate: {
        ytd_actual: number;
        projected_annual: number;
        manual_estimate: number | null;
        used_estimate: number;
        tax_estimate: number;
    };
    previous_year_tax: number;
    payments: Array<{
        id: number;
        date: string | null;
        reference: string | null;
        description: string | null;
    }>;
}>();

const manualEstimate = ref(props.income_estimate.manual_estimate ? String((props.income_estimate.manual_estimate / 100).toFixed(2)) : '');
const formatCents = (cents: number) => useFormatCurrency((Number(cents) || 0) / 100, 'ZAR');

const applyManualEstimate = () => {
    const cents = Math.round(Number(manualEstimate.value || 0) * 100);
    router.get(route('tax.provisional.index'), { manual_estimate_cents: cents > 0 ? cents : '' }, { preserveState: true, preserveScroll: true, replace: true });
};
</script>

<template>
    <FeatureShell
        title="Tax"
        section="provisional"
        :tabs="taxTabs"
        document-title="Provisional Tax"
        :subtitle="`Experimental estimate · ${tax_year.label} tax year · ${tax_year.start} — ${tax_year.end}`"
    >
        <div class="mt-5 grid gap-4 xl:grid-cols-2">
            <AppCard v-for="period in periods" :key="period.id">
                <div class="flex items-start justify-between">
                    <h3 class="text-lg font-semibold text-slate-900">{{ period.label }}</h3>
                    <AppBadge :variant="period.status === 'paid' ? 'success' : period.status === 'overdue' ? 'danger' : 'warning'">
                        {{ period.status }}
                    </AppBadge>
                </div>
                <p class="mt-1 text-xs text-slate-500">Due {{ period.due_date || '—' }}</p>

                <div class="mt-3 space-y-1 text-sm">
                    <p><span class="text-slate-500">Estimated taxable income:</span> {{ formatCents(period.estimated_income) }}</p>
                    <p><span class="text-slate-500">Suggested payment:</span> <span class="font-semibold text-slate-900">{{ formatCents(period.suggested_payment) }}</span></p>
                    <p><span class="text-slate-500">Safe harbour amount:</span> {{ formatCents(period.safe_harbour) }}</p>
                </div>
                <AppButton class="mt-4" variant="secondary" type="button" disabled>
                    Record Payment
                </AppButton>
                <p class="mt-2 text-xs text-slate-500">Not available yet. Record a journal payment whose description or reference includes “provisional tax” to list it below.</p>
            </AppCard>
        </div>

        <AppCard class="mt-5">
            <h3 class="mb-3 text-lg font-semibold text-slate-900">Income Estimate</h3>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-md border border-slate-200 p-3">
                    <p class="text-xs text-slate-500">YTD actual income</p>
                    <p class="mt-1 text-base font-semibold text-slate-900">{{ formatCents(income_estimate.ytd_actual) }}</p>
                </div>
                <div class="rounded-md border border-slate-200 p-3">
                    <p class="text-xs text-slate-500">Projected annual</p>
                    <p class="mt-1 text-base font-semibold text-slate-900">{{ formatCents(income_estimate.projected_annual) }}</p>
                </div>
                <div class="rounded-md border border-slate-200 p-3">
                    <p class="text-xs text-slate-500">Previous year assessed tax</p>
                    <p class="mt-1 text-base font-semibold text-slate-900">{{ formatCents(previous_year_tax) }}</p>
                </div>
                <div class="rounded-md border border-slate-200 p-3">
                    <p class="text-xs text-slate-500">Current rough tax estimate</p>
                    <p class="mt-1 text-base font-semibold text-slate-900">{{ formatCents(income_estimate.tax_estimate) }}</p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-end gap-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Manual annual estimate override</label>
                    <AppInput v-model="manualEstimate" type="number" />
                </div>
                <AppButton variant="secondary" @click="applyManualEstimate">Apply estimate</AppButton>
            </div>

            <p class="mt-4 text-sm text-amber-800">
                Experimental. Rough SA individual tables only — not a filing engine and not tax advice.
                Recorded payments are matched by description/reference text containing “provisional” and “tax”.
                The period Record Payment button is not wired. Consult your accountant for amounts you file.
            </p>
        </AppCard>

        <AppCard class="mt-5">
            <h3 class="mb-3 text-lg font-semibold text-slate-900">Recorded Provisional Tax Payments</h3>
            <AppTable
                :columns="[
                    { key: 'date', label: 'Date' },
                    { key: 'reference', label: 'Reference' },
                    { key: 'description', label: 'Description' },
                ]"
                :page="1"
                :last-page="1"
            >
                <tr v-for="payment in payments" :key="payment.id">
                    <td class="px-4 py-3">{{ payment.date || '—' }}</td>
                    <td class="px-4 py-3">{{ payment.reference || '—' }}</td>
                    <td class="px-4 py-3">{{ payment.description || '—' }}</td>
                </tr>
                <tr v-if="!payments.length">
                    <td colspan="3" class="px-4 py-6">
                        <EmptyState title="No provisional payments recorded" description="Record provisional tax payments to track submissions." />
                    </td>
                </tr>
            </AppTable>
        </AppCard>
    </FeatureShell>
</template>
