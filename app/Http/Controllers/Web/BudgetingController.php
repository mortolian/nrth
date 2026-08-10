<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
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
            ->with(['categories'])
            ->orderByDesc('start_date')
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
                    'period' => $budget->start_date->format('M Y').' - '.$budget->end_date->format('M Y'),
                    'currency' => $budget->currency,
                    'deleted_at' => $budget->deleted_at?->toIso8601String(),
                ];
            })->values()->all();
        }

        $active = $budgetRows->firstWhere('is_active', true);

        $budgets = $budgetRows->map(function (Budget $budget) use ($teamId, $businessCurrency): array {
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
                'business_spend_aligned' => strcasecmp((string) $budget->currency, $businessCurrency) === 0,
            ];
        })->values()->all();

        $activeBudgetPayload = null;
        if ($active !== null) {
            $active->loadMissing(['categories.items', 'categories.account']);
            $categories = $this->budgetCategoryBreakdown($active, $teamId);
            $allocated = (int) collect($categories)->sum('envelope_cents');
            $periodSpentBusiness = strcasecmp((string) $active->currency, $businessCurrency) === 0
                ? $this->spentForPeriod($teamId, $active->start_date->toDateString(), $active->end_date->toDateString())
                : null;
            $spentTotal = $periodSpentBusiness !== null ? (int) $periodSpentBusiness : (int) collect($categories)->sum('spent_cents');
            $activeBudgetPayload = [
                'id' => $active->id,
                'name' => $active->name,
                'period' => $active->start_date->format('M Y').' - '.$active->end_date->format('M Y'),
                'currency' => $active->currency,
                'is_active' => (bool) $active->is_active,
                'total_allocated' => $allocated,
                'total_spent' => $spentTotal,
                'business_spend_aligned' => $periodSpentBusiness !== null,
                'percentage_used' => $allocated > 0 ? (int) round(($spentTotal / $allocated) * 100) : 0,
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
        $allocated = (int) collect($categories)->sum('envelope_cents');
        $periodPlanned = (int) collect($categories)->sum('period_planned_cents');
        $aligned = strcasecmp((string) $budget->currency, $businessCurrency) === 0;
        $spent = $aligned
            ? $this->spentForPeriod($teamId, $budget->start_date->toDateString(), $budget->end_date->toDateString())
            : (int) collect($categories)->sum('spent_cents');

        $canImport = $budget->categories->isEmpty() && $this->previousBudgetForImport($teamId, $budget->id) !== null;

        return Inertia::render('Budgeting/Show', [
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'period_type' => $budget->period_type,
                'period' => $budget->start_date->format('M Y').' - '.$budget->end_date->format('M Y'),
                'start_date' => $budget->start_date?->toDateString(),
                'end_date' => $budget->end_date?->toDateString(),
                'currency' => $budget->currency,
                'is_active' => (bool) $budget->is_active,
                'months_in_period' => $this->monthsInBudgetPeriod($budget->start_date, $budget->end_date),
                'total_allocated' => $allocated,
                'total_planned' => $periodPlanned,
                'total_spent' => $spent,
                'percentage_used' => $allocated > 0 ? (int) round(($spent / $allocated) * 100) : 0,
                'business_spend_aligned' => $aligned,
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
                    'envelope_cents' => (int) $cat->envelope_cents,
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
            'envelope_cents' => (int) $payload['envelope_cents'],
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
            'envelope_cents' => (int) $payload['envelope_cents'],
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
     * @return array{id: int, name: string, period_type: string, start_date: string|null, end_date: string|null, currency: string, is_active: bool}
     */
    private function budgetHeaderPayload(Budget $budget): array
    {
        return [
            'id' => $budget->id,
            'name' => $budget->name,
            'period_type' => $budget->period_type,
            'start_date' => $budget->start_date?->toDateString(),
            'end_date' => $budget->end_date?->toDateString(),
            'currency' => $budget->currency,
            'is_active' => (bool) $budget->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBudgetHeader(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'period_type' => ['required', Rule::in(['monthly', 'quarterly', 'annual', 'custom'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'set_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array{name: string, envelope_cents: int, account_id: int|null}
     */
    private function validateCategoryPayload(Request $request): array
    {
        $teamId = (int) $request->user()->current_team_id;

        /** @var array{name: string, envelope_cents: int, account_id: int|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'envelope_cents' => ['required', 'integer', 'min:0'],
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
        $monthsInPeriod = $this->monthsInBudgetPeriod($budget->start_date, $budget->end_date);
        $spentByAccount = $this->spentByExpenseAccount(
            $teamId,
            $budget->start_date->toDateString(),
            $budget->end_date->toDateString()
        );

        return $budget->categories->map(function (BudgetCategory $cat) use ($spentByAccount, $monthsInPeriod): array {
            $monthlyPlanned = (int) $cat->items->sum(
                fn (BudgetItem $item): int => $item->monthlyEquivalentBudgetCents($monthsInPeriod)
            );
            $periodPlanned = (int) $cat->items->sum(
                fn (BudgetItem $item): int => $item->periodTotalBudgetCents($monthsInPeriod)
            );
            $envelope = (int) $cat->envelope_cents;
            $spent = $cat->account_id !== null
                ? (int) ($spentByAccount[$cat->account_id] ?? 0)
                : 0;
            $percent = $envelope > 0 ? (int) round(($spent / $envelope) * 100) : ($spent > 0 ? 100 : 0);
            $plannedVsEnvelope = $envelope > 0 ? (int) round(($periodPlanned / $envelope) * 100) : 0;

            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'envelope_cents' => $envelope,
                'account_id' => $cat->account_id,
                'account_name' => $cat->account !== null
                    ? trim($cat->account->code.' - '.$cat->account->name)
                    : null,
                'period_planned_cents' => $periodPlanned,
                'monthly_planned_cents' => $monthlyPlanned,
                'planned_fill_percent' => min(100, $plannedVsEnvelope),
                'spent_cents' => $spent,
                'has_account' => $cat->account_id !== null,
                'percentage' => $percent,
                'remaining_cents' => max(0, $envelope - $spent),
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
