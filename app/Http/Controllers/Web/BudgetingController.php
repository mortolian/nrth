<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Budgeting\Enums\BudgetItemCadence;
use App\Domain\Budgeting\Models\Budget;
use App\Domain\Budgeting\Models\BudgetCategory;
use App\Domain\Budgeting\Models\BudgetItem;
use App\Http\Controllers\Controller;
use App\Support\BudgetFx;
use App\Support\Iso4217Currencies;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BudgetingController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeTeam('budgets.view', $request);

        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('budgets')) {
            return Inertia::render('Budgeting/Index', [
                'budgets' => [],
                'trashed_budgets' => [],
                'active_budget' => null,
                'business_currency' => 'ZAR',
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;
        $team = $request->user()->currentTeam;
        $businessCurrency = Iso4217Currencies::normalize(
            (string) ($team?->mergedBusinessSettings()['invoice_default_currency'] ?? 'ZAR')
        );

        $budgetRows = Budget::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->with(['categories.items', 'categories.account'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();

        $trashedBudgets = [];
        if (Schema::hasColumn('budgets', 'deleted_at')) {
            $trashedBudgetRows = Budget::queryWithoutTeamScope()
                ->onlyTrashed()
                ->where('team_id', $teamId)
                ->orderByDesc('deleted_at')
                ->get(['id', 'name', 'start_date', 'end_date', 'currency', 'deleted_at']);

            $trashedBudgets = $trashedBudgetRows->map(function (Budget $budget): array {
                return [
                    'id' => $budget->id,
                    'name' => $budget->name,
                    'period' => $this->budgetPeriodLabel($budget),
                    'currency' => $budget->currency,
                    'deleted_at' => $budget->deleted_at?->toIso8601String(),
                ];
            })->values()->all();
        }

        $active = $budgetRows->firstWhere('is_active', true);

        $budgets = $budgetRows->map(function (Budget $budget) use ($teamId, $businessCurrency): array {
            $categories = $this->budgetCategoryBreakdown($budget, $teamId);
            $periodPlanned = (int) collect($categories)->sum('period_planned_cents');
            $hasTracking = collect($categories)->contains(fn (array $cat): bool => (bool) $cat['has_account']);
            $trackedSpent = (int) collect($categories)->sum('spent_cents');
            $trackedPlanned = (int) collect($categories)
                ->filter(fn (array $cat): bool => (bool) $cat['has_account'])
                ->sum('period_planned_cents');

            return [
                'id' => $budget->id,
                'name' => $budget->name,
                'period' => $this->budgetPeriodLabel($budget),
                'has_period' => $budget->hasPeriod(),
                'currency' => $budget->currency,
                'total_planned' => $periodPlanned,
                'has_tracking' => $hasTracking,
                'total_spent' => $hasTracking ? $trackedSpent : 0,
                'percentage_used' => ($hasTracking && $trackedPlanned > 0)
                    ? (int) round(($trackedSpent / $trackedPlanned) * 100)
                    : 0,
                'status' => $budget->is_active ? 'active' : 'closed',
                'business_spend_aligned' => strcasecmp((string) $budget->currency, $businessCurrency) === 0,
            ];
        })->values()->all();

        $activeBudgetPayload = null;
        if ($active !== null) {
            $categories = $this->budgetCategoryBreakdown($active, $teamId);
            $periodPlanned = (int) collect($categories)->sum('period_planned_cents');
            $monthlyPlanned = (int) collect($categories)->sum('monthly_planned_cents');
            $hasTracking = collect($categories)->contains(fn (array $cat): bool => (bool) $cat['has_account']);
            $trackedSpent = (int) collect($categories)->sum('spent_cents');
            $trackedPlanned = (int) collect($categories)
                ->filter(fn (array $cat): bool => (bool) $cat['has_account'])
                ->sum('period_planned_cents');

            $activeBudgetPayload = [
                'id' => $active->id,
                'name' => $active->name,
                'period' => $this->budgetPeriodLabel($active),
                'has_period' => $active->hasPeriod(),
                'currency' => $active->currency,
                'is_active' => (bool) $active->is_active,
                'total_planned' => $periodPlanned,
                'total_monthly_planned' => $monthlyPlanned,
                'has_tracking' => $hasTracking,
                'total_spent' => $hasTracking ? $trackedSpent : 0,
                'business_spend_aligned' => strcasecmp((string) $active->currency, $businessCurrency) === 0,
                'percentage_used' => ($hasTracking && $trackedPlanned > 0)
                    ? (int) round(($trackedSpent / $trackedPlanned) * 100)
                    : 0,
                'categories' => $categories,
            ];
        }

        return Inertia::render('Budgeting/Index', [
            'budgets' => $budgets,
            'trashed_budgets' => $trashedBudgets,
            'active_budget' => $activeBudgetPayload,
            'business_currency' => $businessCurrency,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeTeam('budgets.manage', $request);

        return Inertia::render('Budgeting/Form', [
            'isEditing' => false,
            'budget' => null,
        ]);
    }

    public function show(Request $request, Budget $budget): Response
    {
        $this->authorizeTeam('budgets.view', $request);
        $this->assertBudgetOwnsTeam($request, $budget);

        $budget->loadMissing(['categories.items', 'categories.account']);

        $teamId = (int) $request->user()->current_team_id;
        $team = $request->user()->currentTeam;
        $businessCurrency = Iso4217Currencies::normalize(
            (string) ($team?->mergedBusinessSettings()['invoice_default_currency'] ?? 'ZAR')
        );

        $categories = $this->budgetCategoryBreakdown($budget, $teamId);
        $periodPlanned = (int) collect($categories)->sum('period_planned_cents');
        $monthlyPlanned = (int) collect($categories)->sum('monthly_planned_cents');
        $hasTracking = collect($categories)->contains(fn (array $cat): bool => (bool) $cat['has_account']);
        $trackedSpent = (int) collect($categories)->sum('spent_cents');
        $trackedPlanned = (int) collect($categories)
            ->filter(fn (array $cat): bool => (bool) $cat['has_account'])
            ->sum('period_planned_cents');

        $canImport = $budget->categories->isEmpty() && $this->previousBudgetForImport($teamId, $budget->id) !== null;

        return Inertia::render('Budgeting/Show', [
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'period_type' => $budget->period_type,
                'period' => $this->budgetPeriodLabel($budget),
                'has_period' => $budget->hasPeriod(),
                'start_date' => $budget->start_date?->toDateString(),
                'end_date' => $budget->end_date?->toDateString(),
                'currency' => $budget->currency,
                'is_active' => (bool) $budget->is_active,
                'months_in_period' => $this->monthsInBudgetPeriodFor($budget),
                'total_planned' => $periodPlanned,
                'total_monthly_planned' => $monthlyPlanned,
                'has_tracking' => $hasTracking,
                'total_spent' => $hasTracking ? $trackedSpent : 0,
                'percentage_used' => ($hasTracking && $trackedPlanned > 0)
                    ? (int) round(($trackedSpent / $trackedPlanned) * 100)
                    : 0,
                'business_spend_aligned' => strcasecmp((string) $budget->currency, $businessCurrency) === 0,
                'categories' => $categories,
            ],
            'expense_accounts' => $this->expenseAccounts($teamId),
            'can_import_structure' => $canImport,
            'business_currency' => $businessCurrency,
        ]);
    }

    public function edit(Request $request, Budget $budget): Response
    {
        $this->authorizeTeam('budgets.manage', $request);
        $this->assertBudgetOwnsTeam($request, $budget);

        return Inertia::render('Budgeting/Form', [
            'isEditing' => true,
            'budget' => $this->budgetHeaderPayload($budget),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $payload = $this->validateBudgetHeader($request);
        $teamId = (int) $request->user()->current_team_id;

        $budget = Budget::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            'name' => $payload['name'],
            'period_type' => $payload['period_type'],
            'start_date' => $payload['start_date'],
            'end_date' => $payload['end_date'],
            'currency' => $payload['currency'],
            'is_active' => (bool) ($payload['set_active'] ?? false),
        ]);

        return to_route('budgeting.show', $budget)->with('success', __('Budget created. Add categories and line items to finish the plan.'));
    }

    public function update(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $this->assertBudgetOwnsTeam($request, $budget);
        $payload = $this->validateBudgetHeader($request);

        $budget->update([
            'name' => $payload['name'],
            'period_type' => $payload['period_type'],
            'start_date' => $payload['start_date'],
            'end_date' => $payload['end_date'],
            'currency' => $payload['currency'],
            'is_active' => (bool) ($payload['set_active'] ?? false),
        ]);

        return to_route('budgeting.show', $budget)->with('success', __('Budget details updated.'));
    }

    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $this->assertBudgetOwnsTeam($request, $budget);
        $budget->delete();

        return to_route('budgeting.index')->with('success', __('Budget moved to trash.'));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $teamId = (int) $request->user()->current_team_id;
        $budget = Budget::queryWithoutTeamScope()
            ->onlyTrashed()
            ->where('team_id', $teamId)
            ->findOrFail($id);
        $budget->restore();

        return to_route('budgeting.index')->with('success', __('Budget restored.'));
    }

    public function forceDestroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $teamId = (int) $request->user()->current_team_id;
        $budget = Budget::queryWithoutTeamScope()
            ->onlyTrashed()
            ->where('team_id', $teamId)
            ->findOrFail($id);
        $budget->forceDelete();

        return to_route('budgeting.index')->with('success', __('Budget permanently deleted.'));
    }

    public function importStructure(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $this->assertBudgetOwnsTeam($request, $budget);

        if ($budget->categories()->exists()) {
            return back()->with('error', __('Import is only available when this budget has no categories yet.'));
        }

        $previous = $this->previousBudgetForImport((int) $request->user()->current_team_id, $budget->id);
        if ($previous === null) {
            return back()->with('error', __('No previous budget found to copy from.'));
        }

        $previous->loadMissing(['categories.items']);
        $budgetCurrency = Iso4217Currencies::normalize((string) $budget->currency);

        DB::transaction(function () use ($budget, $previous, $budgetCurrency): void {
            foreach ($previous->categories as $ci => $cat) {
                $newCat = $budget->categories()->create([
                    'name' => $cat->name,
                    'account_id' => $cat->account_id,
                    'sort_order' => $ci,
                ]);

                foreach ($cat->items as $ii => $item) {
                    $lineMinor = (int) $item->monthly_amount_cents;
                    $lineCcy = Iso4217Currencies::normalize((string) $item->currency);
                    $fx = $item->fx_budget_per_line_major !== null ? (string) $item->fx_budget_per_line_major : null;

                    $budgetMinor = BudgetFx::monthlyLineMinorToBudgetMinor(
                        $lineMinor,
                        $lineCcy,
                        $budgetCurrency,
                        strcasecmp($lineCcy, $budgetCurrency) === 0 ? null : $fx
                    );

                    $newCat->items()->create([
                        'label' => $item->label,
                        'cadence' => $item->cadenceEnum()->value,
                        'monthly_amount_cents' => $lineMinor,
                        'currency' => $lineCcy,
                        'monthly_budget_currency_cents' => $budgetMinor,
                        'fx_budget_per_line_major' => strcasecmp($lineCcy, $budgetCurrency) === 0 ? null : $fx,
                        'notes' => $item->notes,
                        'sort_order' => $ii,
                    ]);
                }
            }
        });

        return back()->with('success', __('Categories and line items copied from the previous budget.'));
    }

    public function storeCategory(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $this->assertBudgetOwnsTeam($request, $budget);
        $payload = $this->validateCategoryPayload($request);

        $nextSort = (int) $budget->categories()->max('sort_order') + 1;
        $budget->categories()->create([
            'name' => $payload['name'],
            'account_id' => $payload['account_id'] ?? null,
            'sort_order' => $nextSort,
        ]);

        return back()->with('success', __('Category added.'));
    }

    public function updateCategory(Request $request, Budget $budget, BudgetCategory $category): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $this->assertBudgetOwnsTeam($request, $budget);
        $this->assertCategoryBelongsToBudget($budget, $category);
        $payload = $this->validateCategoryPayload($request);

        $category->update([
            'name' => $payload['name'],
            'account_id' => $payload['account_id'] ?? null,
        ]);

        return back()->with('success', __('Category updated.'));
    }

    public function destroyCategory(Request $request, Budget $budget, BudgetCategory $category): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $this->assertBudgetOwnsTeam($request, $budget);
        $this->assertCategoryBelongsToBudget($budget, $category);
        $category->delete();

        return back()->with('success', __('Category deleted.'));
    }

    public function storeItem(Request $request, Budget $budget, BudgetCategory $category): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $this->assertBudgetOwnsTeam($request, $budget);
        $this->assertCategoryBelongsToBudget($budget, $category);
        $payload = $this->validateItemPayload($request, (string) $budget->currency);

        $nextSort = (int) $category->items()->max('sort_order') + 1;
        $category->items()->create($this->itemAttributes($payload, (string) $budget->currency, $nextSort));

        return back()->with('success', __('Line item added.'));
    }

    public function updateItem(Request $request, Budget $budget, BudgetCategory $category, BudgetItem $item): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $this->assertBudgetOwnsTeam($request, $budget);
        $this->assertCategoryBelongsToBudget($budget, $category);
        $this->assertItemBelongsToCategory($category, $item);
        $payload = $this->validateItemPayload($request, (string) $budget->currency);

        $item->update($this->itemAttributes($payload, (string) $budget->currency, (int) $item->sort_order));

        return back()->with('success', __('Line item updated.'));
    }

    public function destroyItem(Request $request, Budget $budget, BudgetCategory $category, BudgetItem $item): RedirectResponse
    {
        $this->authorizeTeam('budgets.manage', $request);
        $this->assertBudgetOwnsTeam($request, $budget);
        $this->assertCategoryBelongsToBudget($budget, $category);
        $this->assertItemBelongsToCategory($category, $item);
        $item->delete();

        return back()->with('success', __('Line item deleted.'));
    }

    /**
     * @return array{id: int, name: string, has_period: bool, period_type: string|null, start_date: string|null, end_date: string|null, currency: string, is_active: bool}
     */
    private function budgetHeaderPayload(Budget $budget): array
    {
        return [
            'id' => $budget->id,
            'name' => $budget->name,
            'has_period' => $budget->hasPeriod(),
            'period_type' => $budget->period_type,
            'start_date' => $budget->start_date?->toDateString(),
            'end_date' => $budget->end_date?->toDateString(),
            'currency' => $budget->currency,
            'is_active' => (bool) $budget->is_active,
        ];
    }

    /**
     * @return array{name: string, period_type: string|null, start_date: string|null, end_date: string|null, currency: string, set_active: bool}
     */
    private function validateBudgetHeader(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'has_period' => ['nullable', 'boolean'],
            'period_type' => ['nullable', 'string', Rule::in(['monthly', 'quarterly', 'annual', 'custom'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'set_active' => ['nullable', 'boolean'],
        ]);

        $hasPeriod = (bool) ($validated['has_period'] ?? false);

        if ($hasPeriod) {
            if (empty($validated['period_type'])) {
                throw ValidationException::withMessages([
                    'period_type' => 'Choose a period type.',
                ]);
            }
            if (empty($validated['start_date'])) {
                throw ValidationException::withMessages([
                    'start_date' => 'Start date is required when a budget period is enabled.',
                ]);
            }
            if (empty($validated['end_date'])) {
                throw ValidationException::withMessages([
                    'end_date' => 'End date is required when a budget period is enabled.',
                ]);
            }
            if ($validated['end_date'] < $validated['start_date']) {
                throw ValidationException::withMessages([
                    'end_date' => 'End date must be on or after the start date.',
                ]);
            }

            return [
                'name' => $validated['name'],
                'period_type' => $validated['period_type'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'currency' => $validated['currency'],
                'set_active' => (bool) ($validated['set_active'] ?? false),
            ];
        }

        return [
            'name' => $validated['name'],
            'period_type' => null,
            'start_date' => null,
            'end_date' => null,
            'currency' => $validated['currency'],
            'set_active' => (bool) ($validated['set_active'] ?? false),
        ];
    }

    private function budgetPeriodLabel(Budget $budget): string
    {
        if (! $budget->hasPeriod()) {
            return 'Ongoing Budget';
        }

        return $budget->start_date->format('M Y').' - '.$budget->end_date->format('M Y');
    }

    private function monthsInBudgetPeriodFor(Budget $budget): int
    {
        if (! $budget->hasPeriod()) {
            return 1;
        }

        return $this->monthsInBudgetPeriod($budget->start_date, $budget->end_date);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function trackingWindowFor(Budget $budget): array
    {
        if ($budget->hasPeriod()) {
            return [
                $budget->start_date->toDateString(),
                $budget->end_date->toDateString(),
            ];
        }

        $now = now();

        return [
            $now->copy()->startOfMonth()->toDateString(),
            $now->copy()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * @return array{name: string, account_id: int|null}
     */
    private function validateCategoryPayload(Request $request): array
    {
        $teamId = (int) $request->user()->current_team_id;

        /** @var array{name: string, account_id: int|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('team_id', $teamId)],
        ]);

        return $data;
    }

    /**
     * @return array{label: string, cadence: string, monthly_amount_cents: int, currency: string, fx_budget_per_line_major: string|null, notes: string|null}
     */
    private function validateItemPayload(Request $request, string $budgetCurrency): array
    {
        /** @var array{label: string, cadence: string, monthly_amount_cents: int, currency: string, fx_budget_per_line_major: string|null, notes: string|null} $data */
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'cadence' => ['required', 'string', Rule::in(BudgetItemCadence::values())],
            'monthly_amount_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'fx_budget_per_line_major' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $budgetCurrency = Iso4217Currencies::normalize($budgetCurrency);
        $lineCcy = Iso4217Currencies::normalize($data['currency']);
        if (strcasecmp($lineCcy, $budgetCurrency) !== 0) {
            $fx = $data['fx_budget_per_line_major'] ?? null;
            if ($fx === null || $fx === '' || (float) $fx <= 0) {
                throw ValidationException::withMessages([
                    'fx_budget_per_line_major' => 'Enter the exchange rate from the line currency to the budget currency (budget units per one line unit).',
                ]);
            }
        }

        $notes = $data['notes'] ?? null;
        $data['notes'] = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;

        return $data;
    }

    /**
     * @param  array{label: string, cadence: string, monthly_amount_cents: int, currency: string, fx_budget_per_line_major: string|null, notes: string|null}  $payload
     * @return array{label: string, cadence: string, monthly_amount_cents: int, currency: string, monthly_budget_currency_cents: int, fx_budget_per_line_major: string|null, notes: string|null, sort_order: int}
     */
    private function itemAttributes(array $payload, string $budgetCurrency, int $sortOrder): array
    {
        $budgetCurrency = Iso4217Currencies::normalize($budgetCurrency);
        $lineMinor = (int) $payload['monthly_amount_cents'];
        $lineCcy = Iso4217Currencies::normalize($payload['currency']);
        $fx = $payload['fx_budget_per_line_major'] ?? null;
        $fx = ($fx !== null && $fx !== '') ? (string) $fx : null;

        $budgetMinor = BudgetFx::monthlyLineMinorToBudgetMinor(
            $lineMinor,
            $lineCcy,
            $budgetCurrency,
            strcasecmp($lineCcy, $budgetCurrency) === 0 ? null : $fx
        );

        return [
            'label' => $payload['label'],
            'cadence' => $payload['cadence'],
            'monthly_amount_cents' => $lineMinor,
            'currency' => $lineCcy,
            'monthly_budget_currency_cents' => $budgetMinor,
            'fx_budget_per_line_major' => strcasecmp($lineCcy, $budgetCurrency) === 0 ? null : $fx,
            'notes' => $payload['notes'] ?? null,
            'sort_order' => $sortOrder,
        ];
    }

    private function assertBudgetOwnsTeam(Request $request, Budget $budget): void
    {
        abort_unless($budget->team_id === $request->user()->current_team_id, 403);
    }

    private function assertCategoryBelongsToBudget(Budget $budget, BudgetCategory $category): void
    {
        abort_unless((int) $category->budget_id === (int) $budget->id, 404);
    }

    private function assertItemBelongsToCategory(BudgetCategory $category, BudgetItem $item): void
    {
        abort_unless((int) $item->budget_category_id === (int) $category->id, 404);
    }

    private function previousBudgetForImport(int $teamId, int $excludeBudgetId): ?Budget
    {
        return Budget::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('id', '!=', $excludeBudgetId)
            ->with(['categories.items'])
            ->orderByDesc('start_date')
            ->first();
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function expenseAccounts(int $teamId): array
    {
        return Account::queryWithoutTeamScope()
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
    }

    private function monthsInBudgetPeriod(Carbon $start, Carbon $end): int
    {
        $s = $start->copy()->startOfMonth();
        $e = $end->copy()->startOfMonth();

        return max(1, (int) $s->diffInMonths($e) + 1);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function budgetCategoryBreakdown(Budget $budget, int $teamId): array
    {
        $monthsInPeriod = $this->monthsInBudgetPeriodFor($budget);
        [$from, $to] = $this->trackingWindowFor($budget);
        $spentByAccount = $this->spentByExpenseAccount($teamId, $from, $to);

        return $budget->categories->map(function (BudgetCategory $cat) use ($spentByAccount, $monthsInPeriod): array {
            $monthlyPlanned = (int) $cat->items->sum(
                fn (BudgetItem $item): int => $item->monthlyEquivalentBudgetCents($monthsInPeriod)
            );
            $periodPlanned = (int) $cat->items->sum(
                fn (BudgetItem $item): int => $item->periodTotalBudgetCents($monthsInPeriod)
            );
            $hasAccount = $cat->account_id !== null;
            $spent = $hasAccount
                ? (int) ($spentByAccount[$cat->account_id] ?? 0)
                : 0;
            $percent = $hasAccount && $periodPlanned > 0
                ? (int) round(($spent / $periodPlanned) * 100)
                : ($hasAccount && $spent > 0 ? 100 : 0);

            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'account_id' => $cat->account_id,
                'account_name' => $cat->account !== null
                    ? trim($cat->account->code.' - '.$cat->account->name)
                    : null,
                'period_planned_cents' => $periodPlanned,
                'monthly_planned_cents' => $monthlyPlanned,
                'spent_cents' => $spent,
                'has_account' => $hasAccount,
                'percentage' => $percent,
                'remaining_cents' => $hasAccount ? max(0, $periodPlanned - $spent) : 0,
                'items' => $cat->items->map(fn (BudgetItem $item) => [
                    'id' => $item->id,
                    'label' => $item->label,
                    'cadence' => $item->cadenceEnum()->value,
                    'notes' => $item->notes,
                    'monthly_amount_cents' => (int) $item->monthly_amount_cents,
                    'currency' => $item->currency,
                    'fx_budget_per_line_major' => $item->fx_budget_per_line_major !== null
                        ? (string) $item->fx_budget_per_line_major
                        : null,
                    'monthly_budget_currency_cents' => $item->monthlyEquivalentBudgetCents($monthsInPeriod),
                    'amount_budget_currency_cents' => (int) $item->monthly_budget_currency_cents,
                    'period_total_budget_cents' => $item->periodTotalBudgetCents($monthsInPeriod),
                    'annualized_budget_cents' => $item->annualizedBudgetCents($monthsInPeriod),
                ])->values()->all(),
            ];
        })->values()->all();
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
                ->forPeriodReporting()
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
}
