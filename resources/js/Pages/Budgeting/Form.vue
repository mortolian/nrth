<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { z } from 'zod';
import Sortable from 'sortablejs';
import { Menu, Trash2 } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';

type Item = {
    uid: string;
    label: string;
    monthly_amount_cents: number;
    currency: string;
    fx_budget_per_line_major: string;
};

type Category = {
    uid: string;
    name: string;
    envelope_cents: number;
    account_id: number | '' | null;
    items: Item[];
};

const props = defineProps<{
    isEditing: boolean;
    budget: null | {
        id: number;
        name: string;
        period_type: 'monthly' | 'quarterly' | 'annual' | 'custom';
        start_date: string | null;
        end_date: string | null;
        currency: string;
        is_active: boolean;
        categories: Array<{
            name: string;
            envelope_cents: number;
            account_id: number | null;
            items: Array<{
                label: string;
                monthly_amount_cents: number;
                currency: string;
                fx_budget_per_line_major: string;
            }>;
        }>;
    };
    expense_accounts: Array<{ id: number; name: string }>;
    import_categories: Array<{
        name: string;
        envelope_cents: number;
        account_id: number | null;
        items: Array<{
            label: string;
            monthly_amount_cents: number;
            currency: string;
            fx_budget_per_line_major: string;
        }>;
    }>;
}>();

const page = usePage();
const currencyOptions = computed(
    () => (page.props.currencyOptions as Array<{ value: string; label: string }>) ?? [],
);

const expenseAccountOptions = computed(() =>
    (props.expense_accounts ?? []).map((a) => ({ label: a.name, value: String(a.id) })),
);

const today = new Date().toISOString().slice(0, 10);

function makeUid(): string {
    return crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function mapImportedItem(it: {
    label: string;
    monthly_amount_cents: number;
    currency: string;
    fx_budget_per_line_major?: string;
}): Item {
    return {
        uid: makeUid(),
        label: it.label,
        monthly_amount_cents: Number(it.monthly_amount_cents) || 0,
        currency: it.currency,
        fx_budget_per_line_major: it.fx_budget_per_line_major ?? '',
    };
}

function mapImportedCategory(c: {
    name: string;
    envelope_cents: number;
    account_id: number | null;
    items: Array<{
        label: string;
        monthly_amount_cents: number;
        currency: string;
        fx_budget_per_line_major: string;
    }>;
}): Category {
    return {
        uid: makeUid(),
        name: c.name,
        envelope_cents: Number(c.envelope_cents) || 0,
        account_id: c.account_id ?? '',
        items: c.items.map((it) => mapImportedItem(it)),
    };
}

function mapBudgetCategory(
    c: NonNullable<typeof props.budget>['categories'][0],
): Category {
    return {
        uid: makeUid(),
        name: c.name,
        envelope_cents: Number(c.envelope_cents) || 0,
        account_id: c.account_id ?? '',
        items: c.items.map((it) => mapImportedItem(it)),
    };
}

function initialCategories(): Category[] {
    if (props.budget?.categories?.length) {
        return props.budget.categories.map(mapBudgetCategory);
    }
    return [
        {
            uid: makeUid(),
            name: 'General',
            envelope_cents: 0,
            account_id: '',
            items: [
                {
                    uid: makeUid(),
                    label: '',
                    monthly_amount_cents: 0,
                    currency: props.budget?.currency ?? 'ZAR',
                    fx_budget_per_line_major: '',
                },
            ],
        },
    ];
}

const form = reactive({
    name: props.budget?.name ?? '',
    period_type: (props.budget?.period_type ?? 'monthly') as 'monthly' | 'quarterly' | 'annual' | 'custom',
    start_date: props.budget?.start_date ?? today,
    end_date: props.budget?.end_date ?? today,
    currency: props.budget?.currency ?? 'ZAR',
    set_active: props.budget?.is_active ?? true,
    categories: initialCategories() as Category[],
});

function monthsInPeriod(start: string, end: string): number {
    const s = new Date(`${start}T12:00:00`);
    const e = new Date(`${end}T12:00:00`);
    if (Number.isNaN(s.getTime()) || Number.isNaN(e.getTime())) return 12;
    const sm = s.getFullYear() * 12 + s.getMonth();
    const em = e.getFullYear() * 12 + e.getMonth();
    return Math.max(1, em - sm + 1);
}

/** Match server BudgetFx: Brick default fraction digits */
function minorDigits(code: string): number {
    const c = code.toUpperCase();
    if (c === 'JPY' || c === 'KRW' || c === 'VND' || c === 'CLP' || c === 'UGX') return 0;
    if (c === 'BHD' || c === 'IQD' || c === 'JOD' || c === 'KWD' || c === 'LYD' || c === 'OMR' || c === 'TND') return 3;
    return 2;
}

/** Display minor units as a decimal major-unit string (e.g. ZAR cents → "123.45" rands). */
function centsToMajorStr(cents: number, ccy: string): string {
    const d = minorDigits(ccy);
    const n = (Number(cents) || 0) / 10 ** d;
    return n.toFixed(d);
}

/**
 * Normalise pasted/typed money strings (thousands separators, EU decimal comma) before strict parse.
 */
function normalizeMajorAmountForParse(raw: string): string {
    let s = raw.trim().replace(/\s/g, '');
    if (s === '' || s === '-') {
        return s;
    }
    const hasComma = s.includes(',');
    const hasDot = s.includes('.');
    if (hasComma && hasDot) {
        const lastComma = s.lastIndexOf(',');
        const lastDot = s.lastIndexOf('.');
        if (lastDot > lastComma) {
            s = s.replace(/,/g, '');
        } else {
            s = s.replace(/\./g, '').replace(',', '.');
        }
    } else if (hasComma && !hasDot) {
        const parts = s.split(',');
        if (
            parts.length === 2 &&
            parts[0] !== '' &&
            parts[1] !== '' &&
            parts[1].length <= 2 &&
            /^\d+$/.test(parts[0]) &&
            /^\d+$/.test(parts[1])
        ) {
            s = `${parts[0]}.${parts[1]}`;
        } else {
            s = s.replace(/,/g, '');
        }
    }
    return s;
}

/** Validate and parse amount on field blur (normal currency entry, not raw minor units). */
function parseMajorAmountOnBlur(
    raw: string,
    ccy: string,
): { ok: true; cents: number } | { ok: false; message: string } {
    const d = minorDigits(ccy);
    let s = normalizeMajorAmountForParse(raw.trim().replace(/\s/g, ''));
    if (s === '') {
        return { ok: true, cents: 0 };
    }
    if (!/^(\d+\.?\d*|\.\d+)$/.test(s)) {
        return { ok: false, message: 'Use digits and at most one decimal point (e.g. 1250 or 1250.50).' };
    }
    const n = parseFloat(s);
    if (!Number.isFinite(n) || n < 0) {
        return { ok: false, message: 'Enter a valid amount (zero or greater).' };
    }
    const dot = s.indexOf('.');
    if (dot >= 0) {
        const frac = s.slice(dot + 1);
        if (frac.length > d) {
            return {
                ok: false,
                message: d === 0 ? `${ccy} has no fractional part.` : `Use at most ${d} decimal place(s) for ${ccy}.`,
            };
        }
    }
    return { ok: true, cents: Math.round(n * 10 ** d) };
}

/** Draft text shown while typing; committed to cents on blur only. */
const monthlyAmountDisplay = reactive<Record<string, string>>({});
const monthlyAmountError = reactive<Record<string, string>>({});

function lineCcy(item: Item): string {
    return item.currency || form.currency || 'ZAR';
}

function syncMonthlyDisplayFromCents(item: Item): void {
    const ccy = lineCcy(item);
    monthlyAmountDisplay[item.uid] = centsToMajorStr(item.monthly_amount_cents, ccy);
    delete monthlyAmountError[item.uid];
}

function initAllMonthlyAmountDisplays(): void {
    form.categories.forEach((cat) => {
        cat.items.forEach((item) => syncMonthlyDisplayFromCents(item));
    });
}

function onMonthlyAmountInput(item: Item, val: string | number | null): void {
    monthlyAmountDisplay[item.uid] = String(val ?? '');
    delete monthlyAmountError[item.uid];
}

function onMonthlyAmountBlur(item: Item): void {
    const ccy = lineCcy(item);
    const raw = monthlyAmountDisplay[item.uid] ?? '';
    const result = parseMajorAmountOnBlur(raw, ccy);
    if (!result.ok) {
        monthlyAmountError[item.uid] = result.message;
        return;
    }
    delete monthlyAmountError[item.uid];
    item.monthly_amount_cents = result.cents;
    monthlyAmountDisplay[item.uid] = centsToMajorStr(result.cents, ccy);
}

function onLineCurrencyUpdate(item: Item, ccy: string): void {
    item.currency = ccy;
    syncMonthlyDisplayFromCents(item);
}

/** Commit every monthly text field; returns false if any value is invalid. */
function flushPendingMonthlyAmounts(): boolean {
    let ok = true;
    form.categories.forEach((cat) => {
        cat.items.forEach((item) => {
            const ccy = lineCcy(item);
            const raw = monthlyAmountDisplay[item.uid] ?? '';
            const result = parseMajorAmountOnBlur(raw, ccy);
            if (!result.ok) {
                monthlyAmountError[item.uid] = result.message;
                ok = false;
                return;
            }
            delete monthlyAmountError[item.uid];
            item.monthly_amount_cents = result.cents;
            monthlyAmountDisplay[item.uid] = centsToMajorStr(result.cents, ccy);
        });
    });
    return ok;
}

initAllMonthlyAmountDisplays();

/** Envelope amounts: draft text while typing; committed to cents on blur / save (avoids number-input fighting + selection bugs). */
const envelopeDisplay = reactive<Record<string, string>>({});
const envelopeError = reactive<Record<string, string>>({});

function syncEnvelopeDisplayFromCents(cat: Category): void {
    const ccy = form.currency || 'ZAR';
    envelopeDisplay[cat.uid] = centsToMajorStr(cat.envelope_cents, ccy);
    delete envelopeError[cat.uid];
}

function initAllEnvelopeDisplays(): void {
    form.categories.forEach((cat) => syncEnvelopeDisplayFromCents(cat));
}

function onEnvelopeInput(cat: Category, val: string | number | null): void {
    envelopeDisplay[cat.uid] = String(val ?? '');
    delete envelopeError[cat.uid];
}

function onEnvelopeBlur(cat: Category): void {
    const ccy = form.currency || 'ZAR';
    const raw = envelopeDisplay[cat.uid] ?? '';
    const result = parseMajorAmountOnBlur(raw, ccy);
    if (!result.ok) {
        envelopeError[cat.uid] = result.message;
        return;
    }
    delete envelopeError[cat.uid];
    cat.envelope_cents = result.cents;
    envelopeDisplay[cat.uid] = centsToMajorStr(result.cents, ccy);
}

function flushPendingEnvelopes(): boolean {
    let ok = true;
    const ccy = form.currency || 'ZAR';
    form.categories.forEach((cat) => {
        const raw = envelopeDisplay[cat.uid] ?? '';
        const result = parseMajorAmountOnBlur(raw, ccy);
        if (!result.ok) {
            envelopeError[cat.uid] = result.message;
            ok = false;
            return;
        }
        delete envelopeError[cat.uid];
        cat.envelope_cents = result.cents;
        envelopeDisplay[cat.uid] = centsToMajorStr(result.cents, ccy);
    });
    return ok;
}

initAllEnvelopeDisplays();

function lineToBudgetMonthlyCents(item: Item, budgetCcy: string): number {
    const lineCurrency = item.currency || budgetCcy;
    const lineMinor = Math.max(0, Math.round(Number(item.monthly_amount_cents) || 0));
    if (lineCurrency === budgetCcy) return lineMinor;
    const fx = parseFloat(String(item.fx_budget_per_line_major || '0'));
    if (!fx || fx <= 0) return 0;
    const p = minorDigits(lineCurrency);
    const q = minorDigits(budgetCcy);
    const lineMajor = lineMinor / 10 ** p;
    const budgetMajor = lineMajor * fx;
    return Math.round(budgetMajor * 10 ** q);
}

const monthsCount = computed(() => monthsInPeriod(form.start_date, form.end_date));

const budgetCurrency = computed(() => form.currency || 'ZAR');

/** Budget-side minor units → formatted money (respects ISO minor digits, not always ÷100). */
function formatBudgetMinor(minor: number, ccy: string): string {
    const code = (ccy || 'ZAR').toUpperCase();
    const d = minorDigits(code);
    const major = (Number(minor) || 0) / 10 ** d;
    return new Intl.NumberFormat('en-ZA', {
        style: 'currency',
        currency: code,
        currencyDisplay: 'symbol',
        minimumFractionDigits: d,
        maximumFractionDigits: d,
    }).format(major);
}

/** Frankfurter via {@code invoicing.exchange-rate} — quote (budget) per one unit of base (line). */
function formatRateForStorage(rate: number): string {
    const s = rate.toFixed(10).replace(/\.?0+$/, '');
    return s === '' ? String(rate) : s;
}

function budgetRowNeedsFx(item: Item): boolean {
    const line = (item.currency || '').trim().toUpperCase();
    const budget = (form.currency || '').trim().toUpperCase();
    return line !== '' && line !== budget;
}

function fxTripleKey(item: Item): string {
    return `${lineCcy(item).toUpperCase()}:${(form.currency || 'ZAR').toUpperCase()}:${form.start_date || ''}`;
}

type FxFetchRowState = { loading: boolean; error: string | null; date: string | null; source: 'api' | 'saved' };
const fxFetchState = reactive<Record<string, FxFetchRowState>>({});
const lastSuccessfulFxKey: Record<string, string> = {};
const formHasMounted = ref(false);

function anyFxRowsLoading(): boolean {
    for (const cat of form.categories) {
        for (const item of cat.items) {
            if (budgetRowNeedsFx(item) && fxFetchState[item.uid]?.loading === true) {
                return true;
            }
        }
    }
    return false;
}

/** Server / validation errors delivered via `router.on('error')`, not always visible on `page.props.errors`. */
const inertiaRouterErrorBanner = ref<string | null>(null);
let offBudgetInertiaError: (() => void) | undefined;
let offBudgetInertiaSuccess: (() => void) | undefined;

const pairRateInflight = new Map<string, Promise<{ rate: number; date: string }>>();

async function fetchBudgetPairRate(from: string, to: string, date: string | undefined): Promise<{ rate: number; date: string }> {
    const key = `${from.toUpperCase()}:${to.toUpperCase()}:${date ?? 'latest'}`;
    const existing = pairRateInflight.get(key);
    if (existing) {
        return existing;
    }

    const p = (async () => {
        const params = new URLSearchParams({ from: from.toUpperCase(), to: to.toUpperCase() });
        if (date) {
            params.set('date', date);
        }
        const res = await fetch(`${route('invoicing.exchange-rate')}?${params}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = (await res.json().catch(() => null)) as { rate?: number; date?: string; message?: string } | null;
        if (!res.ok) {
            throw new Error(
                (data && typeof data.message === 'string' && data.message) || 'Could not load exchange rate.',
            );
        }
        if (typeof data?.rate !== 'number' || !Number.isFinite(data.rate) || data.rate <= 0) {
            throw new Error('Invalid rate response.');
        }
        return {
            rate: data.rate,
            date: typeof data.date === 'string' ? data.date : date ?? '',
        };
    })();

    pairRateInflight.set(key, p);
    try {
        return await p;
    } finally {
        pairRateInflight.delete(key);
    }
}

async function applyExchangeRateToItem(item: Item): Promise<void> {
    if (!budgetRowNeedsFx(item)) {
        item.fx_budget_per_line_major = '';
        delete fxFetchState[item.uid];
        return;
    }

    const from = lineCcy(item).toUpperCase();
    const to = form.currency.toUpperCase();
    const date = form.start_date?.trim() || undefined;

    fxFetchState[item.uid] = {
        loading: true,
        error: null,
        date: fxFetchState[item.uid]?.date ?? null,
        source: 'api',
    };

    try {
        const { rate, date: rateDate } = await fetchBudgetPairRate(from, to, date);
        item.fx_budget_per_line_major = formatRateForStorage(rate);
        fxFetchState[item.uid] = { loading: false, error: null, date: rateDate || null, source: 'api' };
    } catch (e) {
        fxFetchState[item.uid] = {
            loading: false,
            error: e instanceof Error ? e.message : 'Could not load exchange rate.',
            date: null,
            source: 'api',
        };
    }
}

function refreshLineFx(item: Item): void {
    delete lastSuccessfulFxKey[item.uid];
    void applyExchangeRateToItem(item).then(() => {
        const key = fxTripleKey(item);
        if (!fxFetchState[item.uid]?.error) {
            lastSuccessfulFxKey[item.uid] = key;
        }
    });
}

const fxDependenciesKey = computed(() =>
    JSON.stringify({
        budget: form.currency,
        start: form.start_date,
        lines: form.categories.flatMap((c) => c.items.map((i) => ({ u: i.uid, c: (i.currency || '').toUpperCase() }))),
    }),
);

watch(
    fxDependenciesKey,
    async () => {
        const mounted = formHasMounted.value;
        const items = form.categories.flatMap((c) => c.items);
        await Promise.all(
            items.map(async (item) => {
                if (!budgetRowNeedsFx(item)) {
                    item.fx_budget_per_line_major = '';
                    delete fxFetchState[item.uid];
                    delete lastSuccessfulFxKey[item.uid];
                    return;
                }

                const key = fxTripleKey(item);
                const hasFx = item.fx_budget_per_line_major.trim() !== '';

                if (lastSuccessfulFxKey[item.uid] === key && hasFx && !fxFetchState[item.uid]?.error) {
                    return;
                }

                if (!mounted && hasFx) {
                    lastSuccessfulFxKey[item.uid] = key;
                    fxFetchState[item.uid] = { loading: false, error: null, date: null, source: 'saved' };
                    return;
                }

                await applyExchangeRateToItem(item);
                if (!fxFetchState[item.uid]?.error) {
                    lastSuccessfulFxKey[item.uid] = key;
                }
            }),
        );
    },
    { immediate: true },
);

function itemPeriodTotal(item: Item): number {
    return lineToBudgetMonthlyCents(item, budgetCurrency.value) * monthsCount.value;
}

function itemAnnualized(item: Item): number {
    return lineToBudgetMonthlyCents(item, budgetCurrency.value) * 12;
}

const applyPeriodType = () => {
    const now = new Date(form.start_date || today);
    if (form.period_type === 'monthly') {
        const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        form.end_date = end.toISOString().slice(0, 10);
    } else if (form.period_type === 'quarterly') {
        const month = now.getMonth();
        const quarterStart = month - (month % 3);
        const start = new Date(now.getFullYear(), quarterStart, 1);
        const end = new Date(now.getFullYear(), quarterStart + 3, 0);
        form.start_date = start.toISOString().slice(0, 10);
        form.end_date = end.toISOString().slice(0, 10);
    } else if (form.period_type === 'annual') {
        const start = new Date(now.getFullYear(), 0, 1);
        const end = new Date(now.getFullYear(), 11, 31);
        form.start_date = start.toISOString().slice(0, 10);
        form.end_date = end.toISOString().slice(0, 10);
    }
};

onMounted(() => {
    formHasMounted.value = true;
    if (!props.isEditing) {
        applyPeriodType();
        const ccy = form.currency;
        form.categories.forEach((cat) => {
            cat.items.forEach((it) => {
                if (!it.currency) it.currency = ccy;
            });
        });
    }
    initAllMonthlyAmountDisplays();
    initAllEnvelopeDisplays();
    offBudgetInertiaError?.();
    offBudgetInertiaSuccess?.();
    offBudgetInertiaError = router.on('error', (errors) => {
        const msgs = collectNestedErrorStrings(errors as unknown);
        inertiaRouterErrorBanner.value = msgs.length ? msgs.join(' ') : null;
    });
    offBudgetInertiaSuccess = router.on('success', () => {
        inertiaRouterErrorBanner.value = null;
    });
    nextTick(() => {
        initBudgetCategorySortable();
        initBudgetLineItemSortables();
    });
});

function addCategory() {
    form.categories.push({
        uid: makeUid(),
        name: '',
        envelope_cents: 0,
        account_id: '',
        items: [
            {
                uid: makeUid(),
                label: '',
                monthly_amount_cents: 0,
                currency: form.currency,
                fx_budget_per_line_major: '',
            },
        ],
    });
    const added = form.categories[form.categories.length - 1];
    added.items.forEach((it) => syncMonthlyDisplayFromCents(it));
    syncEnvelopeDisplayFromCents(added);
}

function removeCategory(idx: number) {
    if (form.categories.length <= 1) return;
    if (!window.confirm('Remove this category and all of its expense lines? You can still save or discard other changes.')) {
        return;
    }
    const cat = form.categories[idx];
    cat.items.forEach((it) => {
        delete monthlyAmountDisplay[it.uid];
        delete monthlyAmountError[it.uid];
    });
    delete envelopeDisplay[cat.uid];
    delete envelopeError[cat.uid];
    form.categories.splice(idx, 1);
}

function addItem(cat: Category) {
    cat.items.push({
        uid: makeUid(),
        label: '',
        monthly_amount_cents: 0,
        currency: form.currency,
        fx_budget_per_line_major: '',
    });
    syncMonthlyDisplayFromCents(cat.items[cat.items.length - 1]);
}

function removeItem(cat: Category, idx: number) {
    if (!window.confirm('Remove this expense line?')) {
        return;
    }
    const it = cat.items[idx];
    delete monthlyAmountDisplay[it.uid];
    delete monthlyAmountError[it.uid];
    cat.items.splice(idx, 1);
}

const budgetCategoriesListRef = ref<HTMLElement | null>(null);
let categorySortable: ReturnType<typeof Sortable.create> | null = null;

const itemSortables = new Map<string, ReturnType<typeof Sortable.create>>();
const itemTbodyByCategory = new Map<string, HTMLTableSectionElement | null>();

const categoriesOrderSig = computed(() => form.categories.map((c) => c.uid).join('|'));

const budgetLineItemsOrderSig = computed(() =>
    form.categories.map((c) => `${c.uid}:${c.items.map((i) => i.uid).join(',')}`).join('|'),
);

function initBudgetCategorySortable() {
    categorySortable?.destroy();
    categorySortable = null;
    const el = budgetCategoriesListRef.value;
    if (!el || el.querySelectorAll(':scope > .budget-category-block').length === 0) {
        return;
    }
    categorySortable = Sortable.create(el, {
        animation: 160,
        handle: '.budget-category-drag-handle',
        draggable: '.budget-category-block',
        ghostClass: 'budget-sortable-ghost',
        filter: sortableInteractFilter,
        /** Must be false: Sortable calls preventDefault on filter hits; that blocks input focus & text selection. */
        preventOnFilter: false,
        onEnd(evt: SortableEndEvent) {
            const { oldIndex, newIndex } = evt;
            if (oldIndex === undefined || newIndex === undefined || oldIndex === newIndex) {
                return;
            }
            const cats = [...form.categories];
            const [moved] = cats.splice(oldIndex, 1);
            cats.splice(newIndex, 0, moved);
            form.categories.splice(0, form.categories.length, ...cats);
        },
    });
}

type SortableEndEvent = { oldIndex?: number; newIndex?: number };

/**
 * Ignore drag when interacting with form controls. Radix Select uses a button/combobox trigger, not an `<input>`,
 * so a narrow filter makes rows/categories impossible to click without starting a drag.
 */
const sortableInteractFilter =
    'input, textarea, button, select, option, [role="combobox"], [role="listbox"], [role="option"], a';

function registerBudgetItemsTbody(catUid: string, el: HTMLTableSectionElement | null) {
    if (el) {
        itemTbodyByCategory.set(catUid, el);
    } else {
        itemTbodyByCategory.delete(catUid);
        itemSortables.get(catUid)?.destroy();
        itemSortables.delete(catUid);
    }
}

function tbodyRefForCategory(catUid: string) {
    return (el: HTMLTableSectionElement | null) => registerBudgetItemsTbody(catUid, el);
}

function initBudgetLineItemSortables() {
    for (const s of itemSortables.values()) {
        s.destroy();
    }
    itemSortables.clear();

    for (const cat of form.categories) {
        const el = itemTbodyByCategory.get(cat.uid);
        if (!el || el.querySelectorAll('tr').length === 0) {
            continue;
        }
        const catUid = cat.uid;
        const sortable = Sortable.create(el, {
            animation: 160,
            handle: '.budget-line-drag-handle',
            draggable: 'tr',
            ghostClass: 'budget-sortable-ghost',
            filter: sortableInteractFilter,
            /** Must be false: Sortable calls preventDefault on filter hits; that blocks input focus & text selection. */
            preventOnFilter: false,
            onEnd(evt: SortableEndEvent) {
                const c = form.categories.find((x) => x.uid === catUid);
                if (!c) {
                    return;
                }
                const { oldIndex, newIndex } = evt;
                if (oldIndex === undefined || newIndex === undefined || oldIndex === newIndex) {
                    return;
                }
                const items = [...c.items];
                const [moved] = items.splice(oldIndex, 1);
                items.splice(newIndex, 0, moved);
                c.items.splice(0, c.items.length, ...items);
            },
        });
        itemSortables.set(cat.uid, sortable);
    }
}

watch(categoriesOrderSig, () => nextTick(() => initBudgetCategorySortable()), { flush: 'post' });

watch(budgetLineItemsOrderSig, () => nextTick(() => initBudgetLineItemSortables()), { flush: 'post' });

onBeforeUnmount(() => {
    offBudgetInertiaError?.();
    offBudgetInertiaError = undefined;
    offBudgetInertiaSuccess?.();
    offBudgetInertiaSuccess = undefined;
    categorySortable?.destroy();
    categorySortable = null;
    for (const s of itemSortables.values()) {
        s.destroy();
    }
    itemSortables.clear();
    itemTbodyByCategory.clear();
});

function importFromPrevious() {
    const src = props.import_categories ?? [];
    if (!src.length) return;
    form.categories = src.map(mapImportedCategory);
    initAllMonthlyAmountDisplays();
    initAllEnvelopeDisplays();
}

/** Client-side validation message (Zod / blur) so failed saves are not silent */
const clientSubmitErrors = ref<string | null>(null);

/** Match server: same-currency lines send null for FX; Zod must accept null, not only undefined */
const submitSchema = z.object({
    name: z.string().min(1),
    period_type: z.enum(['monthly', 'quarterly', 'annual', 'custom']),
    start_date: z.string().min(1),
    end_date: z.string().min(1),
    currency: z.string().length(3).regex(/^[A-Z]{3}$/i, 'Use ISO currency code').transform((s) => s.toUpperCase()),
    set_active: z.boolean().optional(),
    categories: z
        .array(
            z.object({
                name: z.string().min(1),
                envelope_cents: z.coerce.number().int().min(0),
                account_id: z.union([z.coerce.number().int().positive(), z.literal(''), z.null()]).optional(),
                items: z.array(
                    z.object({
                        label: z.string().min(1),
                        monthly_amount_cents: z.coerce.number().int().min(0),
                        currency: z.string().length(3).transform((s) => s.toUpperCase()),
                        fx_budget_per_line_major: z.string().max(32).nullish(),
                    }),
                ),
            }),
        )
        .min(1),
}).superRefine((data, ctx) => {
    const budgetCcy = data.currency.trim().toUpperCase();
    data.categories.forEach((cat, ci) => {
        cat.items.forEach((item, ii) => {
            if (item.label.trim() === '' && item.monthly_amount_cents === 0) {
                return;
            }
            if (item.label.trim() === '') {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Each line needs a name',
                    path: ['categories', ci, 'items', ii, 'label'],
                });
            }
            if (item.label.trim() !== '' && item.monthly_amount_cents < 0) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: 'Invalid amount',
                    path: ['categories', ci, 'items', ii, 'monthly_amount_cents'],
                });
            }
            const lineCcy = item.currency.trim().toUpperCase();
            if (lineCcy !== '' && lineCcy !== budgetCcy) {
                const rawFx = item.fx_budget_per_line_major;
                const fxStr = rawFx == null ? '' : String(rawFx).trim();
                const fxNum = fxStr === '' ? 0 : parseFloat(fxStr);
                const fxOk = Number.isFinite(fxNum) && fxNum > 0;
                if (!fxOk) {
                    ctx.addIssue({
                        code: z.ZodIssueCode.custom,
                        message:
                            'Lines in a currency other than the budget need a positive exchange rate (budget currency per one unit of the line currency).',
                        path: ['categories', ci, 'items', ii, 'fx_budget_per_line_major'],
                    });
                }
            }
        });
    });
});

function trimUnknown(value: unknown): string {
    return String(value ?? '').trim();
}

/** Flatten Laravel / Inertia error bags (possibly nested arrays or plain objects). */
function collectNestedErrorStrings(value: unknown): string[] {
    if (value == null) return [];
    if (typeof value === 'string') {
        const t = value.trim();
        return t.length ? [t] : [];
    }
    if (typeof value === 'number' || typeof value === 'boolean') {
        return [String(value)];
    }
    if (Array.isArray(value)) {
        return value.flatMap(collectNestedErrorStrings);
    }
    if (typeof value === 'object') {
        return Object.values(value as Record<string, unknown>).flatMap(collectNestedErrorStrings);
    }
    return [];
}

const inertiaPageErrorSummary = computed(() => collectNestedErrorStrings(page.props.errors).join(' '));

const combinedSaveBanner = computed(
    () =>
        clientSubmitErrors.value ||
        inertiaRouterErrorBanner.value ||
        inertiaPageErrorSummary.value ||
        '',
);

const submit = () => {
    clientSubmitErrors.value = null;
    inertiaRouterErrorBanner.value = null;

    try {
        if (props.isEditing && !props.budget) {
            clientSubmitErrors.value =
                'This edit screen is missing the budget record — refresh the page or open the budget from the Budgets list.';
            return;
        }

        if (!flushPendingMonthlyAmounts()) {
            clientSubmitErrors.value = 'Fix the highlighted monthly amounts before saving.';
            return;
        }

        if (!flushPendingEnvelopes()) {
            clientSubmitErrors.value = 'Fix the highlighted envelope amounts before saving.';
            return;
        }

        if (anyFxRowsLoading()) {
            clientSubmitErrors.value =
                'Exchange rates are still loading for some lines. Wait a moment, fix any rate errors, then save again.';
            return;
        }

        const budgetCurrencyCode = trimUnknown(form.currency).toUpperCase();

        const payloadTry = {
            name: trimUnknown(form.name),
            period_type: form.period_type,
            start_date: form.start_date,
            end_date: form.end_date,
            currency: budgetCurrencyCode,
            set_active: form.set_active,
            categories: form.categories.map((cat) => ({
                name: trimUnknown(cat.name),
                envelope_cents: Number(cat.envelope_cents) || 0,
                account_id:
                    cat.account_id === '' || cat.account_id === null ? null : Number(cat.account_id),
                items: cat.items
                    .filter(
                        (it) =>
                            trimUnknown(it.label) !== '' || Number(it.monthly_amount_cents) > 0,
                    )
                    .map((it) => {
                        const lineCcy = trimUnknown(it.currency || form.currency).toUpperCase();
                        return {
                            label: trimUnknown(it.label) || '—',
                            monthly_amount_cents: Number(it.monthly_amount_cents) || 0,
                            currency: lineCcy,
                            fx_budget_per_line_major:
                                lineCcy === budgetCurrencyCode
                                    ? null
                                    : trimUnknown(it.fx_budget_per_line_major) || null,
                        };
                    }),
            })),
        };

        const parsed = submitSchema.safeParse(payloadTry);
        if (!parsed.success) {
            clientSubmitErrors.value =
                parsed.error.issues.map((issue) => issue.message).join(' ') ||
                'Check the form and try again.';
            return;
        }

        const plain = JSON.parse(JSON.stringify(parsed.data)) as typeof parsed.data;

        const visitOptions = {
            preserveScroll: true,
            onSuccess: () => {
                clientSubmitErrors.value = null;
            },
            onError: (errors: Record<string, string | string[]>) => {
                const msgs = collectNestedErrorStrings(errors as unknown);
                if (msgs.length) {
                    clientSubmitErrors.value = msgs.join(' ');
                }
            },
        };

        if (props.isEditing && props.budget) {
            router.put(route('budgeting.update', props.budget.id), plain, visitOptions);
            return;
        }
        router.post(route('budgeting.store'), plain, visitOptions);
    } catch (err) {
        console.error(err);
        clientSubmitErrors.value =
            err instanceof Error
                ? err.message
                : 'Something went wrong while preparing the budget to save.';
    }
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit Budget' : 'Create Budget'"
        :breadcrumbs="[
            { label: 'Planning' },
            { label: 'Budgets', href: route('budgeting.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader
            :title="isEditing ? 'Edit Budget' : 'Create Budget'"
            subtitle="Planning: categories with envelopes, known monthly costs, and optional links to ledger accounts for oversight"
        />

        <div
            v-if="combinedSaveBanner"
            class="mt-4 w-full rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
            role="alert"
        >
            {{ combinedSaveBanner }}
        </div>

        <AppCard
            class="mt-5 border-slate-200/90 bg-gradient-to-br from-slate-50 via-white to-brand-50/20 shadow-sm ring-1 ring-slate-200/40"
        >
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Budget name</label>
                    <AppInput v-model="form.name" placeholder="2026 Operating budget" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Period type</label>
                    <AppSelect
                        :model-value="form.period_type"
                        :options="[
                            { label: 'Monthly', value: 'monthly' },
                            { label: 'Quarterly', value: 'quarterly' },
                            { label: 'Annual', value: 'annual' },
                            { label: 'Custom', value: 'custom' },
                        ]"
                        @update:model-value="
                            form.period_type = $event as 'monthly' | 'quarterly' | 'annual' | 'custom';
                            applyPeriodType();
                        "
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Start date</label>
                    <AppInput v-model="form.start_date" type="date" @change="applyPeriodType" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">End date</label>
                    <AppInput v-model="form.end_date" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-500">Budget currency</label>
                    <AppSelect
                        :model-value="form.currency"
                        :options="currencyOptions"
                        @update:model-value="form.currency = $event"
                    />
                </div>
                <div class="md:col-span-2 flex items-center gap-2 pt-2">
                    <input
                        id="budget-set-active"
                        v-model="form.set_active"
                        type="checkbox"
                        class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    >
                    <label for="budget-set-active" class="text-sm text-slate-700">Set as active budget (overview & dashboard)</label>
                </div>
            </div>
        </AppCard>

        <div class="mt-4 flex flex-wrap gap-2">
            <AppButton variant="secondary" @click="addCategory">Add category</AppButton>
            <AppButton v-if="(import_categories ?? []).length" variant="ghost" @click="importFromPrevious">
                Copy structure from last budget
            </AppButton>
        </div>

        <div ref="budgetCategoriesListRef" class="mt-5 flex flex-col gap-5">
            <div v-for="(cat, ci) in form.categories" :key="cat.uid" class="budget-category-block">
                <AppCard>
                <div class="flex items-center gap-3 border-b border-slate-100 pb-3">
                    <button
                        type="button"
                        class="budget-category-drag-handle inline-flex shrink-0 cursor-grab touch-manipulation items-center justify-center rounded-md p-2 text-slate-400 hover:text-slate-600 active:cursor-grabbing"
                        aria-label="Drag to reorder this category"
                    >
                        <Menu class="h-4 w-4 shrink-0" stroke-width="2" />
                    </button>
                    <div class="grid min-w-0 flex-1 gap-3 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Category name</label>
                            <AppInput v-model="cat.name" placeholder="e.g. Facilities" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Envelope (period, {{ form.currency }})</label>
                            <AppInput
                                :model-value="envelopeDisplay[cat.uid] ?? ''"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                                :title="`Envelope cap in ${form.currency} (major units). Applied when you leave the field.`"
                                :class="
                                    envelopeError[cat.uid]
                                        ? 'border-rose-500 ring-1 ring-rose-300/50 focus:ring-rose-400'
                                        : ''
                                "
                                @update:model-value="onEnvelopeInput(cat, $event)"
                                @blur="onEnvelopeBlur(cat)"
                            />
                            <p v-if="envelopeError[cat.uid]" class="mt-0.5 text-xs text-rose-600">
                                {{ envelopeError[cat.uid] }}
                            </p>
                            <p v-else class="mt-0.5 text-xs text-slate-500">
                                Major units (not raw minor units); validated on blur and when saving.
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">Ledger account (optional, for spend tracking)</label>
                            <AppSelect
                                :model-value="cat.account_id === '' || cat.account_id === null ? '' : String(cat.account_id)"
                                :options="[{ label: '— None —', value: '' }, ...expenseAccountOptions]"
                                @update:model-value="cat.account_id = $event === '' ? '' : Number($event)"
                            />
                        </div>
                    </div>
                    <AppButton
                        size="sm"
                        variant="ghost"
                        class="shrink-0 text-rose-600"
                        :disabled="form.categories.length <= 1"
                        @click="removeCategory(ci)"
                    >
                        <Trash2 class="h-4 w-4" />
                    </AppButton>
                </div>

                <div class="mt-3 flex items-start gap-3">
                    <span
                        class="pointer-events-none invisible inline-flex shrink-0 select-none items-center justify-center rounded-md p-2"
                        aria-hidden="true"
                    >
                        <Menu class="h-4 w-4 shrink-0" stroke-width="2" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h4 class="text-sm font-semibold leading-6 text-slate-800">Known monthly expenses</h4>

                        <AppTable
                            class="mt-2"
                            table-class="min-w-[52rem]"
                            :show-pagination="false"
                            :tbody-ref-fn="tbodyRefForCategory(cat.uid)"
                            :columns="[
                                { key: 'ord', label: '', widthClass: 'w-10 px-2' },
                                { key: 'label', label: 'Expense', widthClass: 'min-w-[12rem] w-full max-w-[20rem]' },
                                { key: 'cur', label: 'Currency', widthClass: 'w-32 min-w-[8.5rem] whitespace-nowrap' },
                                { key: 'monthly', label: 'Monthly', widthClass: 'w-28 min-w-[7rem]' },
                                { key: 'fx', label: 'FX rate', widthClass: 'min-w-[8rem] max-w-[11rem]' },
                                { key: 'period', label: 'Period', widthClass: 'text-right whitespace-nowrap tabular-nums' },
                                {
                                    key: 'annual',
                                    label: 'Annual',
                                    widthClass: 'hidden text-right whitespace-nowrap tabular-nums lg:table-cell',
                                },
                                { key: 'x', label: '', widthClass: 'w-12 px-2' },
                            ]"
                            :page="1"
                            :last-page="1"
                        >
                    <tr v-for="(item, ii) in cat.items" :key="item.uid" class="align-middle">
                        <td class="w-px whitespace-nowrap px-2 py-2 align-middle">
                            <div class="flex justify-center">
                                <button
                                    type="button"
                                    class="budget-line-drag-handle inline-flex cursor-grab touch-manipulation items-center justify-center rounded-md p-1.5 text-slate-400 hover:text-slate-600 active:cursor-grabbing"
                                    aria-label="Drag to reorder this line"
                                >
                                    <Menu class="h-4 w-4 shrink-0" stroke-width="2" />
                                </button>
                            </div>
                        </td>
                        <td class="min-w-0 max-w-[20rem] px-3 py-2 align-middle">
                            <AppInput v-model="item.label" class="w-full min-w-0" placeholder="e.g. Rent" />
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 align-middle">
                            <AppSelect
                                :model-value="item.currency"
                                :options="currencyOptions"
                                @update:model-value="onLineCurrencyUpdate(item, $event)"
                            />
                        </td>
                        <td class="px-3 py-2 align-middle">
                            <AppInput
                                :model-value="monthlyAmountDisplay[item.uid] ?? ''"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                                :title="`Amount in ${lineCcy(item)} (major units). Checked when you leave the field.`"
                                :class="
                                    monthlyAmountError[item.uid]
                                        ? 'border-rose-500 ring-1 ring-rose-300/50 focus:ring-rose-400'
                                        : ''
                                "
                                @update:model-value="onMonthlyAmountInput(item, $event)"
                                @blur="onMonthlyAmountBlur(item)"
                            />
                            <p
                                v-if="monthlyAmountError[item.uid]"
                                class="mt-1 line-clamp-2 text-xs leading-snug text-rose-600"
                            >
                                {{ monthlyAmountError[item.uid] }}
                            </p>
                        </td>
                        <td class="px-3 py-2 align-middle">
                            <template v-if="item.currency && item.currency.toUpperCase() !== form.currency.toUpperCase()">
                                <AppInput
                                    v-model="item.fx_budget_per_line_major"
                                    type="text"
                                    :title="`${form.currency} per one ${lineCcy(item)}. Filled from Frankfurter at budget start; you can edit.`"
                                    :disabled="fxFetchState[item.uid]?.loading === true"
                                />
                                <p
                                    v-if="fxFetchState[item.uid]?.loading"
                                    class="mt-1 truncate text-xs text-slate-500"
                                >
                                    Loading rate…
                                </p>
                                <p
                                    v-else-if="fxFetchState[item.uid]?.error"
                                    class="mt-1 line-clamp-2 text-xs leading-snug text-rose-600"
                                >
                                    <span class="break-words">{{ fxFetchState[item.uid]?.error }}</span>
                                    <button
                                        type="button"
                                        class="ml-1 shrink-0 font-medium text-brand-600 underline"
                                        @click="refreshLineFx(item)"
                                    >
                                        Retry
                                    </button>
                                </p>
                                <p
                                    v-else-if="fxFetchState[item.uid]?.date && fxFetchState[item.uid]?.source === 'api'"
                                    class="mt-1 truncate text-xs text-slate-500"
                                    :title="`${form.currency} per 1 ${lineCcy(item)} — Frankfurter, ${fxFetchState[item.uid]?.date}`"
                                >
                                    {{ form.currency }}/{{ lineCcy(item) }} · {{ fxFetchState[item.uid]?.date }}
                                </p>
                                <p v-else class="mt-1 truncate text-xs text-slate-500">
                                    <button
                                        type="button"
                                        class="font-medium text-brand-600 underline"
                                        :title="`Replace with latest Frankfurter rate for ${lineCcy(item)} → ${form.currency} at budget start.`"
                                        @click="refreshLineFx(item)"
                                    >
                                        Refresh rate
                                    </button>
                                </p>
                            </template>
                            <span v-else class="inline-block pt-2 text-xs text-slate-400">—</span>
                        </td>
                        <td class="px-3 py-2 align-middle text-right text-sm font-medium tabular-nums text-slate-800">
                            {{ formatBudgetMinor(itemPeriodTotal(item), form.currency) }}
                        </td>
                        <td
                            class="hidden px-3 py-2 align-middle text-right text-sm font-medium tabular-nums text-slate-800 lg:table-cell"
                        >
                            {{ formatBudgetMinor(itemAnnualized(item), form.currency) }}
                        </td>
                        <td class="px-2 py-2 align-middle text-right">
                            <AppButton
                                size="sm"
                                variant="ghost"
                                class="text-rose-600"
                                @click="removeItem(cat, ii)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </AppButton>
                        </td>
                    </tr>
                        </AppTable>

                        <div class="mt-3 flex justify-center">
                            <AppButton size="sm" variant="secondary" @click="addItem(cat)">Add line</AppButton>
                        </div>

                        <p class="mt-3 text-xs leading-relaxed text-slate-500">
                            Amounts are in each line’s currency (major units). If the line currency differs from the budget,
                            the exchange rate is indicative (Frankfurter at budget start). Period total = monthly (in
                            {{ form.currency }}) × {{ monthsCount }} month(s) in range; annualised uses × 12.
                        </p>
                    </div>
                </div>
            </AppCard>
            </div>
        </div>

        <div class="mt-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
            <div class="flex gap-2">
                <AppButton variant="ghost" @click="router.visit(route('budgeting.index'))">Back to budgets</AppButton>
                <AppButton variant="primary" @click="submit">Save budget</AppButton>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Sortable drop placeholder (rows + category blocks) */
:deep(tr.budget-sortable-ghost) {
    opacity: 0.75;
    background-color: rgb(241 245 249);
}
:deep(.budget-category-block.budget-sortable-ghost .rounded-xl) {
    box-shadow: inset 0 0 0 2px rgb(148 163 184 / 0.45);
}
</style>
