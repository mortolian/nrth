<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useFormatCurrency } from '@/Composables/useFormatCurrency';
import { useToast } from '@/Composables/useToast';

const props = defineProps<{
    item: {
        id: number;
        name: string;
        description: string | null;
        unit: string | null;
        unit_price_cents: number;
        default_vat_rate: number | null;
        is_active: boolean;
    };
    default_currency: string;
    can: {
        manage: boolean;
        delete: boolean;
    };
}>();

const toast = useToast();

const formatCents = (cents: number) =>
    useFormatCurrency((Number(cents) || 0) / 100, props.default_currency || 'ZAR');

const vatLabel = computed(() => {
    if (props.item.default_vat_rate === null || props.item.default_vat_rate === undefined) {
        return '—';
    }
    const pct = Number(props.item.default_vat_rate) * 100;
    return `${pct % 1 === 0 ? pct.toFixed(0) : pct.toFixed(2)}%`;
});

const destroyItem = () => {
    if (!confirm('Delete this item? Existing invoice lines keep their text and price.')) {
        return;
    }

    router.delete(route('invoicing.items.destroy', props.item.id), {
        onSuccess: () => toast.success('Item deleted.'),
    });
};
</script>

<template>
    <AppLayout
        :title="item.name"
        :breadcrumbs="[
            { label: 'Money In', href: route('invoicing.invoices.index') },
            { label: 'Items', href: route('invoicing.items.index') },
            { label: item.name },
        ]"
    >
        <PageHeader :title="item.name" subtitle="Catalog item for invoices and estimates">
            <template #actions>
                <AppButton
                    v-if="can.manage"
                    variant="primary"
                    @click="router.visit(route('invoicing.items.edit', item.id))"
                >
                    Edit
                </AppButton>
                <AppButton v-if="can.delete" variant="danger" @click="destroyItem">Delete</AppButton>
            </template>
        </PageHeader>

        <div class="mt-5 space-y-6">
            <div class="flex flex-wrap items-center gap-2">
                <AppBadge :variant="item.is_active ? 'success' : 'neutral'">
                    {{ item.is_active ? 'Active' : 'Inactive' }}
                </AppBadge>
                <AppBadge v-if="!item.is_active" variant="warning">Hidden from pickers</AppBadge>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Unit price</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
                        {{ formatCents(item.unit_price_cents) }}
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Unit</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900">{{ item.unit || '—' }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Default VAT</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ vatLabel }}</p>
                </div>
            </div>

            <AppCard>
                <h3 class="text-base font-semibold text-slate-900">Details</h3>
                <dl class="mt-5 grid gap-6 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Name</dt>
                        <dd class="mt-1 text-sm font-medium text-slate-900">{{ item.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Picker visibility</dt>
                        <dd class="mt-1 text-sm text-slate-700">
                            {{
                                item.is_active
                                    ? 'Available when adding lines on invoices and estimates'
                                    : 'Inactive — not offered in line pickers'
                            }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Description</dt>
                        <dd class="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-slate-800">
                            {{ item.description || '—' }}
                        </dd>
                    </div>
                </dl>
            </AppCard>
        </div>
    </AppLayout>
</template>
