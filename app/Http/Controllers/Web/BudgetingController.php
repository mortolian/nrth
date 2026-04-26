<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Budgeting\Models\Budget;
use App\Domain\Budgeting\Models\BudgetLine;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\RedirectResponse;
use Inertia\Response;

class BudgetingController extends Controller
{
    public function index(Request $request): Response
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('budgets')) {
            return Inertia::render('Budgeting/Index', [
                'budgets' => [],
                'active_budget' => null,
                'monthly_variance' => [],
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;
        $budgetRows = Budget::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->with(['lines.account'])
            ->orderByDesc('start_date')
            ->get();

        $active = $budgetRows->firstWhere('is_active', true) ?? $budgetRows->first();
        $months = collect(range(0, 5))->map(fn (int $i) => now()->subMonths(5 - $i)->startOfMonth());

        $monthlySeries = $months->map(function ($month) use ($teamId): array {
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

            $budgeted = (int) max(100_00, round($spent * 1.15));

            return [
                'month' => $month->format('M Y'),
                'budgeted' => $budgeted,
                'actual' => $spent,
                'variance' => $budgeted - $spent,
            ];
        });

        $budgets = $budgetRows->map(function (Budget $budget): array {
            $allocated = (int) $budget->lines->sum('annual_total_cents');
            $spent = $this->spentForPeriod((int) $budget->team_id, $budget->start_date->toDateString(), $budget->end_date->toDateString());

            return [
                'id' => $budget->id,
                'name' => $budget->name,
                'period' => $budget->start_date->format('M Y').' - '.$budget->end_date->format('M Y'),
                'total_allocated' => $allocated,
                'total_spent' => $spent,
                'percentage_used' => $allocated > 0 ? (int) round(($spent / $allocated) * 100) : 0,
                'status' => $budget->is_active ? 'active' : 'closed',
            ];
        })->values()->all();

        $activeBudgetPayload = null;
        if ($active !== null) {
            $spentByAccount = $this->spentByExpenseAccount(
                $teamId,
                $active->start_date->toDateString(),
                $active->end_date->toDateString()
            );

            $categories = $active->lines->map(function (BudgetLine $line) use ($spentByAccount): array {
                $allocated = (int) $line->annual_total_cents;
                $spent = (int) ($spentByAccount[$line->account_id] ?? 0);
                $remaining = max(0, $allocated - $spent);
                $percent = $allocated > 0 ? (int) round(($spent / $allocated) * 100) : 0;

                return [
                    'category' => $line->account?->name ?? 'Unknown',
                    'allocated' => $allocated,
                    'spent' => $spent,
                    'remaining' => $remaining,
                    'percentage' => $percent,
                    'trend' => $percent > 80 ? 'faster' : 'slower',
                ];
            })->values()->all();

            $allocated = (int) collect($categories)->sum('allocated');
            $spent = (int) collect($categories)->sum('spent');
            $activeBudgetPayload = [
                'id' => $active->id,
                'name' => $active->name,
                'period' => $active->start_date->format('M Y').' - '.$active->end_date->format('M Y'),
                'total_allocated' => $allocated,
                'total_spent' => $spent,
                'percentage_used' => $allocated > 0 ? (int) round(($spent / $allocated) * 100) : 0,
                'categories' => $categories,
            ];
        }

        return Inertia::render('Budgeting/Index', [
            'budgets' => $budgets,
            'active_budget' => $activeBudgetPayload,
            'monthly_variance' => $monthlySeries->values()->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Budgeting/Form', [
            'isEditing' => false,
            'budget' => null,
            ...$this->formMeta($request),
        ]);
    }

    public function edit(Request $request, Budget $budget): Response
    {
        abort_unless($budget->team_id === $request->user()->current_team_id, 403);
        $budget->loadMissing('lines.account');

        return Inertia::render('Budgeting/Form', [
            'isEditing' => true,
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'period_type' => $budget->period_type,
                'start_date' => $budget->start_date?->toDateString(),
                'end_date' => $budget->end_date?->toDateString(),
                'currency' => $budget->currency,
                'lines' => $budget->lines->map(fn (BudgetLine $line) => [
                    'account_id' => $line->account_id,
                    'account_name' => $line->account?->name ?? 'Unknown',
                    'monthly_amount_cents' => (int) $line->monthly_amount_cents,
                    'annual_total_cents' => (int) $line->annual_total_cents,
                ])->values()->all(),
            ],
            ...$this->formMeta($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateBudget($request);
        $teamId = (int) $request->user()->current_team_id;

        $budget = DB::transaction(function () use ($teamId, $payload): Budget {
            if (! empty($payload['set_active'])) {
                Budget::queryWithoutTeamScope()
                    ->where('team_id', $teamId)
                    ->update(['is_active' => false]);
            }

            $budget = Budget::queryWithoutTeamScope()->create([
                'team_id' => $teamId,
                'name' => $payload['name'],
                'period_type' => $payload['period_type'],
                'start_date' => $payload['start_date'],
                'end_date' => $payload['end_date'],
                'currency' => $payload['currency'],
                'is_active' => (bool) ($payload['set_active'] ?? false),
            ]);

            foreach ($payload['lines'] as $line) {
                $monthly = (int) $line['monthly_amount_cents'];
                $budget->lines()->create([
                    'account_id' => (int) $line['account_id'],
                    'monthly_amount_cents' => $monthly,
                    'annual_total_cents' => $monthly * 12,
                ]);
            }

            return $budget;
        });

        return to_route('budgeting.index');
    }

    public function update(Request $request, Budget $budget): RedirectResponse
    {
        abort_unless($budget->team_id === $request->user()->current_team_id, 403);
        $payload = $this->validateBudget($request);
        $teamId = (int) $request->user()->current_team_id;

        DB::transaction(function () use ($budget, $payload, $teamId): void {
            if (! empty($payload['set_active'])) {
                Budget::queryWithoutTeamScope()
                    ->where('team_id', $teamId)
                    ->update(['is_active' => false]);
            }

            $budget->update([
                'name' => $payload['name'],
                'period_type' => $payload['period_type'],
                'start_date' => $payload['start_date'],
                'end_date' => $payload['end_date'],
                'currency' => $payload['currency'],
                'is_active' => (bool) ($payload['set_active'] ?? false),
            ]);

            $budget->lines()->delete();
            foreach ($payload['lines'] as $line) {
                $monthly = (int) $line['monthly_amount_cents'];
                $budget->lines()->create([
                    'account_id' => (int) $line['account_id'],
                    'monthly_amount_cents' => $monthly,
                    'annual_total_cents' => $monthly * 12,
                ]);
            }
        });

        return to_route('budgeting.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formMeta(Request $request): array
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

        $previousBudget = Budget::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->with('lines')
            ->orderByDesc('start_date')
            ->first();

        return [
            'expense_accounts' => $expenseAccounts,
            'import_lines' => $previousBudget?->lines->map(fn (BudgetLine $line) => [
                'account_id' => $line->account_id,
                'monthly_amount_cents' => (int) $line->monthly_amount_cents,
            ])->values()->all() ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBudget(Request $request): array
    {
        $teamId = (int) $request->user()->current_team_id;

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'period_type' => ['required', Rule::in(['monthly', 'quarterly', 'annual', 'custom'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'currency' => ['required', Rule::in(['ZAR'])],
            'set_active' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('team_id', $teamId)],
            'lines.*.monthly_amount_cents' => ['required', 'integer', 'min:0'],
        ]);
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
