<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Budgeting\Models\Budget;
use App\Domain\Budgeting\Models\BudgetCategory;
use App\Domain\Budgeting\Models\BudgetItem;
use App\Http\Controllers\Controller;
use App\Support\BudgetFx;
use App\Support\Iso4217Currencies;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BudgetingController extends Controller
{
    public function index(Request $request): Response
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('budgets')) {
            return Inertia::render('Budgeting/Index', [
                'budgets' => [],
                'active_budget' => null,
                'company_currency' => 'ZAR',
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;
        $team = $request->user()->currentTeam;
        $companyCurrency = Iso4217Currencies::normalize(
            (string) ($team?->mergedCompanySettings()['invoice_default_currency'] ?? 'ZAR')
        );

        $budgetRows = Budget::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->with(['categories.items', 'categories.account'])
            ->orderByDesc('start_date')
            ->get();

        $active = $budgetRows->firstWhere('is_active', true);
        $months = collect(range(0, 5))->map(fn (int $i) => now()->subMonths(5 - $i)->startOfMonth());

        $varianceAligned = $active === null || strcasecmp((string) $active->currency, $companyCurrency) === 0;
        $periodSpentCompany = $active !== null && $varianceAligned
            ? $this->spentForPeriod($teamId, $active->start_date->toDateString(), $active->end_date->toDateString())
            : null;

        $budgets = $budgetRows->map(function (Budget $budget) use ($teamId, $companyCurrency, $months): array {
            $allocated = (int) $budget->categories->sum('envelope_cents');
            $spent = $this->spentForPeriod((int) $budget->team_id, $budget->start_date->toDateString(), $budget->end_date->toDateString());

            return [
                'id' => $budget->id,
                'name' => $budget->name,
                'period' => $budget->start_date->format('M Y').' - '.$budget->end_date->format('M Y'),
                'currency' => $budget->currency,
                'total_allocated' => $allocated,
                'total_spent' => $spent,
                'percentage_used' => $allocated > 0 ? (int) round(($spent / $allocated) * 100) : 0,
                'status' => $budget->is_active ? 'active' : 'closed',
                'categories' => $this->budgetIndexCategoryBreakdown($budget, $teamId),
                'company_spend_aligned' => strcasecmp((string) $budget->currency, $companyCurrency) === 0,
                'monthly_variance' => $this->monthlyVarianceSeriesForBudget($budget, $teamId, $companyCurrency, $months),
            ];
        })->values()->all();

        $activeBudgetPayload = null;
        if ($active !== null) {
            $categories = $this->budgetIndexCategoryBreakdown($active, $teamId);
            $allocated = (int) collect($categories)->sum('envelope_cents');
            $spentTotal = $periodSpentCompany !== null ? (int) $periodSpentCompany : (int) collect($categories)->sum('spent_cents');
            $activeBudgetPayload = [
                'id' => $active->id,
                'name' => $active->name,
                'period' => $active->start_date->format('M Y').' - '.$active->end_date->format('M Y'),
                'currency' => $active->currency,
                'is_active' => (bool) $active->is_active,
                'total_allocated' => $allocated,
                'total_spent' => $spentTotal,
                'company_spend_aligned' => $periodSpentCompany !== null,
                'percentage_used' => $allocated > 0 ? (int) round(($spentTotal / $allocated) * 100) : 0,
                'categories' => $categories,
            ];
        }

        return Inertia::render('Budgeting/Index', [
            'budgets' => $budgets,
            'active_budget' => $activeBudgetPayload,
            'company_currency' => $companyCurrency,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Budgeting/Form', [
            'isEditing' => false,
            'budget' => null,
            ...$this->formMeta($request, null),
        ]);
    }

    public function edit(Request $request, Budget $budget): Response
    {
        abort_unless($budget->team_id === $request->user()->current_team_id, 403);
        $budget->loadMissing(['categories.items', 'categories.account']);

        return Inertia::render('Budgeting/Form', [
            'isEditing' => true,
            'budget' => $this->budgetPayload($budget),
            ...$this->formMeta($request, $budget->id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateBudgetPayload($request);
        $teamId = (int) $request->user()->current_team_id;

        DB::transaction(function () use ($teamId, $payload): void {
            $budget = Budget::queryWithoutTeamScope()->create([
                'team_id' => $teamId,
                'name' => $payload['name'],
                'period_type' => $payload['period_type'],
                'start_date' => $payload['start_date'],
                'end_date' => $payload['end_date'],
                'currency' => $payload['currency'],
                'is_active' => (bool) ($payload['set_active'] ?? false),
            ]);

            $this->syncCategories($budget, $payload['categories'], $payload['currency']);
        });

        return to_route('budgeting.index');
    }

    public function update(Request $request, Budget $budget): RedirectResponse
    {
        abort_unless($budget->team_id === $request->user()->current_team_id, 403);
        $payload = $this->validateBudgetPayload($request);

        DB::transaction(function () use ($budget, $payload): void {
            $budget->update([
                'name' => $payload['name'],
                'period_type' => $payload['period_type'],
                'start_date' => $payload['start_date'],
                'end_date' => $payload['end_date'],
                'currency' => $payload['currency'],
                'is_active' => (bool) ($payload['set_active'] ?? false),
            ]);

            $budget->categories()->delete();
            $this->syncCategories($budget, $payload['categories'], $payload['currency']);
        });

        return to_route('budgeting.index');
    }

    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        abort_unless($budget->team_id === $request->user()->current_team_id, 403);
        $budget->delete();

        return to_route('budgeting.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetPayload(Budget $budget): array
    {
        return [
            'id' => $budget->id,
            'name' => $budget->name,
            'period_type' => $budget->period_type,
            'start_date' => $budget->start_date?->toDateString(),
            'end_date' => $budget->end_date?->toDateString(),
            'currency' => $budget->currency,
            'is_active' => (bool) $budget->is_active,
            'categories' => $budget->categories->map(fn (BudgetCategory $cat) => [
                'name' => $cat->name,
                'envelope_cents' => (int) $cat->envelope_cents,
                'account_id' => $cat->account_id,
                'items' => $cat->items->map(fn (BudgetItem $item) => [
                    'label' => $item->label,
                    'monthly_amount_cents' => (int) $item->monthly_amount_cents,
                    'currency' => $item->currency,
                    'fx_budget_per_line_major' => $item->fx_budget_per_line_major !== null
                        ? (string) $item->fx_budget_per_line_major
                        : '',
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, array{name: string, envelope_cents: int, account_id: int|null, items: array<int, array{label: string, monthly_amount_cents: int, currency: string, fx_budget_per_line_major: string|null}>}>  $categories
     */
    private function syncCategories(Budget $budget, array $categories, string $budgetCurrency): void
    {
        $budgetCurrency = Iso4217Currencies::normalize($budgetCurrency);

        foreach ($categories as $ci => $catPayload) {
            $category = $budget->categories()->create([
                'name' => $catPayload['name'],
                'envelope_cents' => (int) $catPayload['envelope_cents'],
                'account_id' => $catPayload['account_id'] ?? null,
                'sort_order' => $ci,
            ]);

            foreach ($catPayload['items'] as $ii => $itemPayload) {
                $lineMinor = (int) $itemPayload['monthly_amount_cents'];
                $lineCcy = Iso4217Currencies::normalize($itemPayload['currency']);
                $fx = $itemPayload['fx_budget_per_line_major'] ?? null;
                $fx = ($fx !== null && $fx !== '') ? (string) $fx : null;

                $budgetMinor = BudgetFx::monthlyLineMinorToBudgetMinor(
                    $lineMinor,
                    $lineCcy,
                    $budgetCurrency,
                    strcasecmp($lineCcy, $budgetCurrency) === 0 ? null : $fx
                );

                $category->items()->create([
                    'label' => $itemPayload['label'],
                    'monthly_amount_cents' => $lineMinor,
                    'currency' => $lineCcy,
                    'monthly_budget_currency_cents' => $budgetMinor,
                    'fx_budget_per_line_major' => strcasecmp($lineCcy, $budgetCurrency) === 0 ? null : $fx,
                    'sort_order' => $ii,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formMeta(Request $request, ?int $excludeBudgetId): array
    {
        $teamId = (int) $request->user()->current_team_id;
        $expenseAccounts = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', AccountType::Expense->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => trim($account->code.' - '.$account->name),
            ])
            ->all();

        $previousQuery = Budget::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->with(['categories.items'])
            ->orderByDesc('start_date');

        if ($excludeBudgetId !== null) {
            $previousQuery->where('id', '!=', $excludeBudgetId);
        }

        $previousBudget = $previousQuery->first();

        $importCategories = [];
        if ($previousBudget !== null) {
            $importCategories = $previousBudget->categories->map(fn (BudgetCategory $cat) => [
                'name' => $cat->name,
                'envelope_cents' => (int) $cat->envelope_cents,
                'account_id' => $cat->account_id,
                'items' => $cat->items->map(fn (BudgetItem $item) => [
                    'label' => $item->label,
                    'monthly_amount_cents' => (int) $item->monthly_amount_cents,
                    'currency' => $item->currency,
                    'fx_budget_per_line_major' => $item->fx_budget_per_line_major !== null
                        ? (string) $item->fx_budget_per_line_major
                        : '',
                ])->values()->all(),
            ])->values()->all();
        }

        return [
            'expense_accounts' => $expenseAccounts,
            'import_categories' => $importCategories,
        ];
    }

    private function monthsInBudgetPeriod(Carbon $start, Carbon $end): int
    {
        $s = $start->copy()->startOfMonth();
        $e = $end->copy()->startOfMonth();

        return max(1, (int) $s->diffInMonths($e) + 1);
    }

    /**
     * Last six calendar months: budgeted vs actual (when budget currency matches company books currency).
     *
     * @param  Collection<int, Carbon>  $months
     * @return array<int, array{month: string, budgeted: int, actual: int|null, variance: int|null}>
     */
    private function monthlyVarianceSeriesForBudget(Budget $budget, int $teamId, string $companyCurrency, Collection $months): array
    {
        $aligned = strcasecmp((string) $budget->currency, $companyCurrency) === 0;

        return $months->map(function (Carbon $month) use ($teamId, $budget, $aligned): array {
            $from = $month->copy()->startOfMonth()->toDateString();
            $to = $month->copy()->endOfMonth()->toDateString();

            $spent = (int) JournalEntry::query()
                ->where('type', EntryType::Debit)
                ->whereHas('transaction', fn ($q) => $q
                    ->withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->where('status', TransactionStatus::Posted->value)
                    ->whereBetween('transaction_date', [$from, $to]))
                ->whereHas('account', fn ($q) => $q
                    ->withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->where('type', AccountType::Expense->value))
                ->sum('amount_cents');

            $budgeted = $this->budgetedCentsForCalendarMonth($budget, $month);

            return [
                'month' => $month->format('M Y'),
                'budgeted' => $budgeted,
                'actual' => $aligned ? $spent : null,
                'variance' => $aligned ? ($budgeted - $spent) : null,
            ];
        })->values()->all();
    }

    /**
     * Category and line-item breakdown for the budgeting index (table expand rows).
     *
     * @return array<int, array<string, mixed>>
     */
    private function budgetIndexCategoryBreakdown(Budget $budget, int $teamId): array
    {
        $monthsInPeriod = $this->monthsInBudgetPeriod($budget->start_date, $budget->end_date);
        $spentByAccount = $this->spentByExpenseAccount(
            $teamId,
            $budget->start_date->toDateString(),
            $budget->end_date->toDateString()
        );

        return $budget->categories->map(function (BudgetCategory $cat) use ($spentByAccount, $monthsInPeriod): array {
            $monthlyPlanned = (int) $cat->items->sum('monthly_budget_currency_cents');
            $periodPlanned = $monthlyPlanned * $monthsInPeriod;
            $envelope = (int) $cat->envelope_cents;
            $spent = $cat->account_id !== null
                ? (int) ($spentByAccount[$cat->account_id] ?? 0)
                : 0;
            $percent = $envelope > 0 ? (int) round(($spent / $envelope) * 100) : ($spent > 0 ? 100 : 0);
            $plannedVsEnvelope = $envelope > 0 ? (int) round(($periodPlanned / $envelope) * 100) : 0;

            return [
                'name' => $cat->name,
                'envelope_cents' => $envelope,
                'period_planned_cents' => $periodPlanned,
                'monthly_planned_cents' => $monthlyPlanned,
                'planned_fill_percent' => min(100, $plannedVsEnvelope),
                'spent_cents' => $spent,
                'has_account' => $cat->account_id !== null,
                'percentage' => $percent,
                'remaining_cents' => max(0, $envelope - $spent),
                'items' => $cat->items->map(fn (BudgetItem $item) => [
                    'label' => $item->label,
                    'monthly_amount_cents' => (int) $item->monthly_amount_cents,
                    'currency' => $item->currency,
                    'monthly_budget_currency_cents' => (int) $item->monthly_budget_currency_cents,
                    'period_total_budget_cents' => (int) $item->monthly_budget_currency_cents * $monthsInPeriod,
                    'annualized_budget_cents' => (int) $item->monthly_budget_currency_cents * 12,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    private function budgetedCentsForCalendarMonth(Budget $budget, Carbon $monthStart): int
    {
        $budget->loadMissing('categories.items');

        $mStart = $monthStart->copy()->startOfMonth();
        $mEnd = $monthStart->copy()->endOfMonth();

        if ($mEnd->lt($budget->start_date) || $mStart->gt($budget->end_date)) {
            return 0;
        }

        $monthlyTotal = (int) $budget->categories->flatMap->items->sum('monthly_budget_currency_cents');

        $overlapStart = $mStart->greaterThan($budget->start_date) ? $mStart : $budget->start_date->copy()->startOfDay();
        $overlapEnd = $mEnd->lessThan($budget->end_date) ? $mEnd : $budget->end_date->copy()->endOfDay();

        if ($overlapStart->greaterThan($overlapEnd)) {
            return 0;
        }

        $daysInMonth = $mStart->daysInMonth;
        $overlapDays = $overlapStart->diffInDays($overlapEnd) + 1;

        return (int) round($monthlyTotal * min(1.0, $overlapDays / $daysInMonth));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBudgetPayload(Request $request): array
    {
        $teamId = (int) $request->user()->current_team_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'period_type' => ['required', Rule::in(['monthly', 'quarterly', 'annual', 'custom'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'set_active' => ['nullable', 'boolean'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*.name' => ['required', 'string', 'max:255'],
            'categories.*.envelope_cents' => ['required', 'integer', 'min:0'],
            'categories.*.account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('team_id', $teamId)],
            'categories.*.items' => ['present', 'array'],
            'categories.*.items.*.label' => ['required', 'string', 'max:255'],
            'categories.*.items.*.monthly_amount_cents' => ['required', 'integer', 'min:0'],
            'categories.*.items.*.currency' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'categories.*.items.*.fx_budget_per_line_major' => ['nullable', 'string', 'max:32'],
        ]);

        $budgetCurrency = Iso4217Currencies::normalize($data['currency']);

        $validator = Validator::make($data, []);
        $validator->after(function (\Illuminate\Validation\Validator $v) use ($data, $budgetCurrency): void {
            foreach ($data['categories'] as $ci => $cat) {
                foreach ($cat['items'] as $ii => $item) {
                    $lineCcy = Iso4217Currencies::normalize($item['currency']);
                    if (strcasecmp($lineCcy, $budgetCurrency) !== 0) {
                        $fx = $item['fx_budget_per_line_major'] ?? null;
                        if ($fx === null || $fx === '' || (float) $fx <= 0) {
                            $v->errors()->add(
                                "categories.$ci.items.$ii.fx_budget_per_line_major",
                                'Enter the exchange rate from the line currency to the budget currency (budget units per one line unit).'
                            );
                        }
                    }
                }
            }
        });
        $validator->validate();

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @return array<int, int>
     */
    private function spentByExpenseAccount(int $teamId, string $from, string $to): array
    {
        return JournalEntry::query()
            ->where('type', EntryType::Debit)
            ->whereHas('transaction', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('status', TransactionStatus::Posted->value)
                ->whereBetween('transaction_date', [$from, $to]))
            ->whereHas('account', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('type', AccountType::Expense->value))
            ->get()
            ->groupBy('account_id')
            ->map(fn ($rows): int => (int) $rows->sum(fn (JournalEntry $entry) => (int) $entry->getRawOriginal('amount_cents')))
            ->toArray();
    }

    private function spentForPeriod(int $teamId, string $from, string $to): int
    {
        return (int) JournalEntry::query()
            ->where('type', EntryType::Debit)
            ->whereHas('transaction', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('status', TransactionStatus::Posted->value)
                ->whereBetween('transaction_date', [$from, $to]))
            ->whereHas('account', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('type', AccountType::Expense->value))
            ->sum('amount_cents');
    }
}
