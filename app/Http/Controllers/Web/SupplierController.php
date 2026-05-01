<?php

namespace App\Http\Controllers\Web;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Http\Controllers\Controller;
use App\Support\Iso4217Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $teamId = (int) $request->user()->current_team_id;
        $search = trim((string) $request->string('search')->toString());
        $status = (string) $request->string('status')->toString();
        $view = (string) $request->string('view')->toString() ?: 'grid';

        $query = Supplier::queryWithoutTeamScope()->where('team_id', $teamId);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $suppliers = $query
            ->withCount('expenseTransactions')
            ->withMax('expenseTransactions', 'transaction_date')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Supplier $supplier): array {
                return [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'contact_name' => $supplier->contact_name,
                    'email' => $supplier->email,
                    'status' => $supplier->is_active ? 'active' : 'inactive',
                    'expense_count' => (int) $supplier->expense_transactions_count,
                    'last_expense_date' => $supplier->expense_transactions_max_transaction_date
                        ? (string) $supplier->expense_transactions_max_transaction_date
                        : null,
                ];
            });

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => [
                'search' => $search ?: null,
                'status' => $status ?: 'all',
                'view' => in_array($view, ['grid', 'table'], true) ? $view : 'grid',
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $returnQuery = $request->query('return');

        return Inertia::render('Suppliers/Form', [
            'isEditing' => false,
            'supplier' => null,
            'return_to' => $this->safeInternalReturn(is_string($returnQuery) ? $returnQuery : null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateSupplier($request);
        $teamId = (int) $request->user()->current_team_id;
        $returnTo = $this->safeInternalReturn(
            is_string($request->input('return')) ? (string) $request->input('return') : null
        );

        $supplier = Supplier::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            ...$payload,
        ]);

        if ($returnTo !== null) {
            return redirect($returnTo);
        }

        return to_route('suppliers.show', $supplier);
    }

    public function show(Request $request, Supplier $supplier): Response
    {
        abort_unless($supplier->team_id === $request->user()->current_team_id, 403);

        $teamId = (int) $supplier->team_id;

        $expenseHistory = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TransactionType::Expense->value)
            ->where('supplier_id', $supplier->id)
            ->with(['journalEntries.account', 'taxLines'])
            ->withCount('media')
            ->orderByDesc('transaction_date')
            ->paginate(25)
            ->withQueryString()
            ->through(function (Transaction $transaction): array {
                $expenseLines = $transaction->journalEntries->filter(
                    fn ($entry) => $entry->account?->type === AccountType::Expense
                );
                $amountCents = (int) $expenseLines->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'));
                $category = (string) ($expenseLines->first()?->account?->name ?? 'Uncategorized');
                $vatAmount = (int) $transaction->taxLines->sum('tax_amount_cents');

                return [
                    'id' => $transaction->id,
                    'date' => optional($transaction->transaction_date)->toDateString(),
                    'category' => $category,
                    'description' => $transaction->description,
                    'amount_cents' => $amountCents,
                    'vat_amount_cents' => $vatAmount,
                    'status' => $transaction->status->value,
                    'has_receipt' => $transaction->media_count > 0,
                ];
            });

        $statsRows = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TransactionType::Expense->value)
            ->where('supplier_id', $supplier->id)
            ->with(['journalEntries.account'])
            ->get();

        $totalExpensesCents = $statsRows->sum(function (Transaction $transaction): int {
            return (int) $transaction->journalEntries
                ->filter(fn ($entry) => $entry->account?->type === AccountType::Expense)
                ->sum(fn ($entry) => (int) $entry->getRawOriginal('amount_cents'));
        });

        return Inertia::render('Suppliers/Show', [
            'supplier' => $this->serializeSupplier($supplier),
            'expense_history' => $expenseHistory,
            'stats' => [
                'total_expenses_cents' => $totalExpensesCents,
                'expense_count' => $statsRows->count(),
                'currency' => Iso4217Currencies::normalize('ZAR'),
            ],
        ]);
    }

    public function edit(Request $request, Supplier $supplier): Response
    {
        abort_unless($supplier->team_id === $request->user()->current_team_id, 403);

        return Inertia::render('Suppliers/Form', [
            'isEditing' => true,
            'supplier' => $this->serializeSupplier($supplier),
        ]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        abort_unless($supplier->team_id === $request->user()->current_team_id, 403);

        $supplier->update($this->validateSupplier($request));

        return to_route('suppliers.show', $supplier);
    }

    public function destroy(Request $request, Supplier $supplier): RedirectResponse
    {
        abort_unless($supplier->team_id === $request->user()->current_team_id, 403);

        if (Transaction::queryWithoutTeamScope()
            ->where('team_id', $supplier->team_id)
            ->where('supplier_id', $supplier->id)
            ->exists()) {
            return back()->withErrors([
                'delete' => __('This supplier has recorded expenses and cannot be deleted.'),
            ]);
        }

        $supplier->delete();

        return to_route('suppliers.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSupplier(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', (new Phone)->international()],
            'vat_number' => ['nullable', 'regex:/^4\d{9}$/'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'array'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.province' => ['nullable', 'string', 'max:255'],
            'address.postal_code' => ['nullable', 'string', 'max:30'],
            'address.country' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! empty($validated['phone'])) {
            $validated['phone'] = (new PhoneNumber((string) $validated['phone']))->formatE164();
        } else {
            $validated['phone'] = null;
        }

        return $validated;
    }

    /**
     * Allow only same-origin paths under /suppliers/ or /expenses/ (prevents open redirects).
     */
    private function safeInternalReturn(?string $return): ?string
    {
        if ($return === null || $return === '') {
            return null;
        }

        $trimmed = trim($return);
        if ($trimmed === '' || str_contains($trimmed, '..') || str_contains($trimmed, "\0")) {
            return null;
        }

        if (! preg_match('#^/(suppliers|expenses)/#', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSupplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'contact_name' => $supplier->contact_name,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'vat_number' => $supplier->vat_number,
            'registration_number' => $supplier->registration_number,
            'address' => $supplier->address ?? [
                'street' => '',
                'city' => '',
                'province' => '',
                'postal_code' => '',
                'country' => 'South Africa',
            ],
            'notes' => $supplier->notes,
            'is_active' => (bool) $supplier->is_active,
        ];
    }
}
