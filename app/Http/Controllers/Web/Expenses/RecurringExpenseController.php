<?php

namespace App\Http\Controllers\Web\Expenses;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Actions\EnsureDefaultBankingAccount;
use App\Domain\Banking\Support\BankingPaymentAccounts;
use App\Domain\Expenses\Actions\GenerateRecurringExpenseAction;
use App\Domain\Expenses\Enums\RecurringExpenseStatus;
use App\Domain\Expenses\Models\RecurringExpense;
use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Database\Seeders\DefaultTaxRatesSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecurringExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeTeam('expenses.view', $request);
        $teamId = (int) $request->user()->current_team_id;
        $status = (string) $request->string('status')->toString();
        $search = trim((string) $request->string('search')->toString());

        $base = RecurringExpense::queryWithoutTeamScope()->where('team_id', $teamId);

        $summary = [
            'active' => (clone $base)->where('status', RecurringExpenseStatus::Active->value)->count(),
            'on_hold' => (clone $base)->where('status', RecurringExpenseStatus::OnHold->value)->count(),
            'completed' => (clone $base)->where('status', RecurringExpenseStatus::Completed->value)->count(),
            'due_soon' => (clone $base)
                ->where('status', RecurringExpenseStatus::Active->value)
                ->whereDate('next_run_date', '<=', now()->addDays(7)->toDateString())
                ->count(),
        ];

        $query = RecurringExpense::queryWithoutTeamScope()
            ->with(['supplier:id,name', 'categoryAccount:id,code,name'])
            ->where('team_id', $teamId);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $query->where(function ($q) use ($pattern): void {
                $q->where('supplier_name', 'like', $pattern)
                    ->orWhere('description', 'like', $pattern)
                    ->orWhere('reference', 'like', $pattern)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $pattern));
            });
        }

        $rows = $query->orderBy('next_run_date')->paginate(25)->withQueryString()
            ->through(fn (RecurringExpense $row): array => $this->serializeList($row));

        return Inertia::render('Expenses/Recurring/Index', [
            'recurring' => $rows,
            'summary' => $summary,
            'filters' => [
                'status' => $status !== '' ? $status : 'all',
                'search' => $search !== '' ? $search : null,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeTeam('expenses.manage', $request);
        $team = $request->user()?->currentTeam;
        abort_if($team === null, 403);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);
        (new DefaultTaxRatesSeeder)->runForTeam($team);
        (new EnsureDefaultBankingAccount)->execute($team);

        $teamId = (int) $team->id;
        $prefillSupplierId = (int) $request->integer('supplier_id');
        $prefill = null;
        if ($prefillSupplierId > 0) {
            $supplierExists = Supplier::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->whereKey($prefillSupplierId)
                ->exists();
            if ($supplierExists) {
                $prefill = [
                    'supplier_id' => $prefillSupplierId,
                    'supplier_custom' => '',
                ];
            }
        }

        return Inertia::render('Expenses/Recurring/Form', [
            'isEditing' => false,
            'recurring' => $prefill,
            ...$this->formMeta($teamId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        $teamId = (int) $request->user()->current_team_id;
        $payload = $this->validatePayload($request, $teamId);

        $recurring = RecurringExpense::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            ...$payload,
            'generated_count' => 0,
            'status' => RecurringExpenseStatus::Active->value,
        ]);

        return to_route('expenses.recurring.show', $recurring)
            ->with('success', __('Recurring expense created.'));
    }

    public function show(Request $request, RecurringExpense $recurringExpense): Response
    {
        $this->authorizeTeam('expenses.view', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $recurringExpense->load([
            'supplier:id,name',
            'categoryAccount:id,code,name',
            'paidFromBankingAccount:id,name',
            'expenses' => fn ($q) => $q->latest('transaction_date')->limit(20),
        ]);

        return Inertia::render('Expenses/Recurring/Show', [
            'recurring' => $this->serializeDetail($recurringExpense),
            'can' => [
                'manage' => $request->user()->canOnTeam('expenses.manage', $request->user()->currentTeam),
                'delete' => $request->user()->canOnTeam('expenses.delete', $request->user()->currentTeam),
            ],
        ]);
    }

    public function edit(Request $request, RecurringExpense $recurringExpense): Response
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $team = $request->user()?->currentTeam;
        abort_if($team === null, 403);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);
        (new DefaultTaxRatesSeeder)->runForTeam($team);
        (new EnsureDefaultBankingAccount)->execute($team);

        return Inertia::render('Expenses/Recurring/Form', [
            'isEditing' => true,
            'recurring' => $this->serializeDetail($recurringExpense),
            ...$this->formMeta((int) $team->id),
        ]);
    }

    public function update(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $payload = $this->validatePayload($request, (int) $recurringExpense->team_id);
        $recurringExpense->update($payload);

        return to_route('expenses.recurring.show', $recurringExpense)
            ->with('success', __('Recurring expense updated.'));
    }

    public function destroy(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorizeTeam('expenses.delete', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $recurringExpense->delete();

        return to_route('expenses.recurring.index')
            ->with('success', __('Recurring expense deleted.'));
    }

    public function pause(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $recurringExpense->update(['status' => RecurringExpenseStatus::OnHold]);

        return back()->with('success', __('Recurring expense paused.'));
    }

    public function resume(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $recurringExpense->update(['status' => RecurringExpenseStatus::Active]);

        return back()->with('success', __('Recurring expense resumed.'));
    }

    public function complete(Request $request, RecurringExpense $recurringExpense): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);
        $recurringExpense->update(['status' => RecurringExpenseStatus::Completed]);

        return back()->with('success', __('Recurring expense marked completed.'));
    }

    public function generateNow(Request $request, RecurringExpense $recurringExpense, GenerateRecurringExpenseAction $action): RedirectResponse
    {
        $this->authorizeTeam('expenses.manage', $request);
        abort_unless($recurringExpense->team_id === $request->user()->current_team_id, 403);

        if ($recurringExpense->status !== RecurringExpenseStatus::Active) {
            $recurringExpense->update(['status' => RecurringExpenseStatus::Active]);
        }

        $expense = $action->execute(
            $recurringExpense->fresh(),
            Carbon::today(),
            (int) $request->user()->id,
        );
        if ($expense === null) {
            return back()->with('error', __('Could not generate expense. Check the template is active, the category and paid-from account are valid, and the supplier is active.'));
        }

        return to_route('expenses.edit', $expense)
            ->with('success', __('Expense generated from recurring template.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, int $teamId): array
    {
        $request->merge([
            'generate_on_last_day' => $request->boolean('generate_on_last_day'),
        ]);

        if ($request->has('supplier_id') && $request->string('supplier_id')->toString() === '') {
            $request->merge(['supplier_id' => null]);
        }

        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $teamId)],
            'supplier' => ['required_without:supplier_id', 'nullable', 'string', 'max:255'],
            'category_account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($q) => $q
                    ->where('team_id', $teamId)
                    ->where('type', AccountType::Expense->value)
                    ->where('is_active', true)),
            ],
            'paid_from_banking_account_id' => [
                'required',
                'integer',
                Rule::exists('banking_accounts', 'id')->where(function ($query) use ($teamId): void {
                    $query->where('team_id', $teamId)
                        ->where('is_active', true)
                        ->whereNotNull('gl_account_id');
                }),
            ],
            'frequency' => ['required', Rule::enum(RecurringFrequency::class)],
            'generate_on_weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
            'generate_on_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'generate_on_last_day' => ['required', 'boolean'],
            'generate_on_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'limit_type' => ['required', Rule::enum(RecurringLimitType::class)],
            'limit_count' => ['nullable', 'integer', 'min:1'],
            'limit_end_date' => ['nullable', 'date'],
            'next_run_date' => ['required', 'date'],
            'period_offset_months' => ['required', 'integer', 'min:-12', 'max:12'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:255'],
            'amount_excl_vat_cents' => ['required', 'integer', 'min:0'],
            'vat_rate' => ['required', Rule::in(['vat15', 'vat0', 'exempt', 'no_vat'])],
            'vat_amount_cents' => ['required', 'integer', 'min:0'],
        ]);

        $category = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->findOrFail((int) $validated['category_account_id']);

        if ($this->isUnsupportedRecurringCategory($category)) {
            throw ValidationException::withMessages([
                'category_account_id' => __('Travel and home office categories need a one-off expense. Recurring templates are for fixed amounts such as rent or subscriptions.'),
            ]);
        }

        $supplierId = isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : 0;

        return [
            'supplier_id' => $supplierId > 0 ? $supplierId : null,
            'supplier_name' => $supplierId > 0 ? null : trim((string) ($validated['supplier'] ?? '')),
            'category_account_id' => (int) $validated['category_account_id'],
            'paid_from_banking_account_id' => (int) $validated['paid_from_banking_account_id'],
            'frequency' => $validated['frequency'],
            'generate_on_weekday' => $validated['generate_on_weekday'] ?? null,
            'generate_on_day' => $validated['generate_on_day'] ?? null,
            'generate_on_last_day' => (bool) $validated['generate_on_last_day'],
            'generate_on_month' => $validated['generate_on_month'] ?? null,
            'limit_type' => $validated['limit_type'],
            'limit_count' => $validated['limit_count'] ?? null,
            'limit_end_date' => $validated['limit_end_date'] ?? null,
            'next_run_date' => $validated['next_run_date'],
            'period_offset_months' => (int) $validated['period_offset_months'],
            'description' => $validated['description'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'reference' => $validated['reference'] ?? null,
            'amount_excl_vat_cents' => (int) $validated['amount_excl_vat_cents'],
            'vat_rate' => $validated['vat_rate'],
            'vat_amount_cents' => (int) $validated['vat_amount_cents'],
        ];
    }

    private function isUnsupportedRecurringCategory(Account $category): bool
    {
        $name = strtolower($category->name);

        return str_contains($name, 'travel') || str_contains($name, 'home office');
    }

    /**
     * @return array<string, mixed>
     */
    private function formMeta(int $teamId): array
    {
        return [
            'categories' => Account::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('type', AccountType::Expense->value)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->reject(fn (Account $account) => $this->isUnsupportedRecurringCategory($account))
                ->values()
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'name' => trim($account->code.' - '.$account->name),
                ])
                ->all(),
            'paid_from_options' => BankingPaymentAccounts::forExpensePaidFrom($teamId),
            'supplier_options' => Supplier::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Supplier $supplier) => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                ])
                ->all(),
            'tax_rates' => [
                ['value' => 'vat15', 'label' => 'VAT 15%', 'rate' => 0.15, 'claimable' => true],
                ['value' => 'vat0', 'label' => 'VAT 0%', 'rate' => 0.0, 'claimable' => true],
                ['value' => 'exempt', 'label' => 'Exempt', 'rate' => 0.0, 'claimable' => false],
                ['value' => 'no_vat', 'label' => 'No VAT', 'rate' => 0.0, 'claimable' => false],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeList(RecurringExpense $row): array
    {
        return [
            'id' => $row->id,
            'supplier_name' => $row->displaySupplier(),
            'category' => $row->categoryAccount
                ? trim($row->categoryAccount->code.' - '.$row->categoryAccount->name)
                : 'Uncategorized',
            'description' => $row->description,
            'status' => $row->status->value,
            'frequency' => $row->frequency->value,
            'next_run_date' => optional($row->next_run_date)->toDateString(),
            'generated_count' => (int) $row->generated_count,
            'amount_excl_vat_cents' => (int) $row->amount_excl_vat_cents,
            'vat_amount_cents' => (int) $row->vat_amount_cents,
            'total_cents' => (int) $row->amount_excl_vat_cents + (int) $row->vat_amount_cents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(RecurringExpense $row): array
    {
        return [
            ...$this->serializeList($row),
            'supplier_id' => $row->supplier_id,
            'supplier_custom' => $row->supplier_id ? '' : (string) ($row->supplier_name ?? ''),
            'category_account_id' => $row->category_account_id,
            'paid_from_banking_account_id' => $row->paid_from_banking_account_id,
            'paid_from_name' => $row->paidFromBankingAccount?->name,
            'generate_on_weekday' => $row->generate_on_weekday,
            'generate_on_day' => $row->generate_on_day,
            'generate_on_last_day' => (bool) $row->generate_on_last_day,
            'generate_on_month' => $row->generate_on_month,
            'limit_type' => $row->limit_type->value,
            'limit_count' => $row->limit_count,
            'limit_end_date' => optional($row->limit_end_date)->toDateString(),
            'period_offset_months' => (int) $row->period_offset_months,
            'notes' => $row->notes,
            'reference' => $row->reference,
            'vat_rate' => $row->vat_rate,
            'last_generated_at' => optional($row->last_generated_at)?->toIso8601String(),
            'expenses' => $row->relationLoaded('expenses')
                ? $row->expenses->map(fn (Transaction $transaction) => [
                    'id' => $transaction->id,
                    'date' => optional($transaction->transaction_date)->toDateString(),
                    'description' => $transaction->description,
                    'status' => $transaction->status->value,
                ])->values()->all()
                : [],
        ];
    }
}
