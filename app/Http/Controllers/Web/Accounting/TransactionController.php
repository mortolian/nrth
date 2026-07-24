<?php

namespace App\Http\Controllers\Web\Accounting;

use App\Domain\Accounting\Actions\DeleteTransactionAction;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Payment;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        if (! Schema::hasTable('transactions')) {
            return Inertia::render('Accounting/Transactions/Index', [
                'transactions' => new LengthAwarePaginator([], 0, 15),
                'filters' => $this->filters($request),
                'accounts' => [],
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;
        $from = (string) $request->string('from')->toString();
        $to = (string) $request->string('to')->toString();
        $type = (string) $request->string('type')->toString();
        $status = (string) $request->string('status')->toString();
        $accountId = (int) $request->integer('account_id');
        $search = trim((string) $request->string('search')->toString());

        $query = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->with([
                'journalEntries.account',
                'supplier:id,name',
                'payments' => fn ($q) => $q
                    ->select(['id', 'team_id', 'invoice_id', 'transaction_id'])
                    ->where('team_id', $teamId)
                    ->with(['invoice:id,team_id,status']),
            ]);

        if ($from !== '') {
            $query->whereDate('transaction_date', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('transaction_date', '<=', $to);
        }
        if ($type !== '' && in_array($type, array_map(fn (TransactionType $t) => $t->value, TransactionType::cases()), true)) {
            $query->where('type', $type);
        }
        if ($status !== '' && in_array($status, array_map(fn (TransactionStatus $s) => $s->value, TransactionStatus::cases()), true)) {
            $query->where('status', $status);
        }
        if ($accountId > 0) {
            $query->whereHas('journalEntries', fn ($q) => $q->where('account_id', $accountId));
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('reference', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('expense_meta->external_reference', 'like', $like)
                    ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', $like));
            });
        }

        $transactions = $query
            ->orderByDesc('transaction_date')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Transaction $transaction): array {
                $lines = $transaction->journalEntries;
                $debits = $lines->filter(fn ($line) => $line->type === EntryType::Debit);
                $credits = $lines->filter(fn ($line) => $line->type === EntryType::Credit);
                $amount = (int) max(
                    $debits->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents')),
                    $credits->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'))
                );

                $debitAccount = $debits->first()?->account?->name ?? '—';
                $creditAccount = $credits->first()?->account?->name ?? '—';

                /** @var Payment|null $invoicePayment */
                $invoicePayment = $transaction->payments->first();
                $invoicePaymentUndo = null;
                if (
                    $invoicePayment !== null
                    && $invoicePayment->invoice !== null
                    && $invoicePayment->invoice->status !== InvoiceStatus::Void
                    && in_array($transaction->status, [TransactionStatus::Posted, TransactionStatus::Draft], true)
                ) {
                    $invoicePaymentUndo = [
                        'invoice_id' => $invoicePayment->invoice_id,
                        'payment_id' => $invoicePayment->id,
                    ];
                }

                return [
                    'id' => $transaction->id,
                    'date' => optional($transaction->transaction_date)->toDateString(),
                    'type' => $transaction->type->value,
                    'reference' => $transaction->displayReference(),
                    'supplier' => $transaction->displaySupplier(),
                    'description' => $transaction->description,
                    'status' => $transaction->status->value,
                    'can_delete' => DeleteTransactionAction::canDelete($transaction),
                    'can_edit_expense' => $transaction->type === TransactionType::Expense,
                    'invoice_payment_undo' => $invoicePaymentUndo,
                    'total_amount' => $amount,
                    'accounts_affected' => $debitAccount.' -> '.$creditAccount,
                    'journal_entries' => $lines->map(fn ($line) => [
                        'id' => $line->id,
                        'account' => $line->account?->name ?? 'Unknown',
                        'type' => $line->type->value,
                        'amount' => (int) $line->getRawOriginal('amount_cents'),
                    ])->values()->all(),
                ];
            });

        $accounts = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => trim($account->code.' - '.$account->name),
            ])
            ->all();

        return Inertia::render('Accounting/Transactions/Index', [
            'transactions' => $transactions,
            'filters' => $this->filters($request),
            'accounts' => $accounts,
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $teamId = (int) $request->user()->current_team_id;

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));

        $transactions = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereIn('id', $ids)
            ->with(['journalEntries.account', 'supplier:id,name'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $filename = 'transactions-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($transactions): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM so Excel / Sheets detect encoding correctly.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Date',
                'Type',
                'Reference',
                'Supplier',
                'Description',
                'Accounts affected',
                'Amount (ZAR)',
                'Status',
            ]);

            foreach ($transactions as $transaction) {
                $lines = $transaction->journalEntries;
                $debits = $lines->filter(fn ($line) => $line->type === EntryType::Debit);
                $credits = $lines->filter(fn ($line) => $line->type === EntryType::Credit);
                $amountCents = (int) max(
                    $debits->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents')),
                    $credits->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents'))
                );
                $debitAccount = $debits->first()?->account?->name ?? '—';
                $creditAccount = $credits->first()?->account?->name ?? '—';

                fputcsv($handle, [
                    optional($transaction->transaction_date)->toDateString() ?? '',
                    $transaction->type->value,
                    (string) ($transaction->displayReference() ?? ''),
                    (string) ($transaction->displaySupplier() ?? ''),
                    (string) ($transaction->description ?? ''),
                    $debitAccount.' -> '.$creditAccount,
                    number_format($amountCents / 100, 2, '.', ''),
                    $transaction->status->value,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function destroy(Request $request, Transaction $transaction, DeleteTransactionAction $deleteTransactionAction): RedirectResponse
    {
        abort_unless($transaction->team_id === (int) $request->user()->current_team_id, 403);

        $deleteTransactionAction->execute($transaction);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'type' => $request->string('type')->toString() ?: 'all',
            'status' => $request->string('status')->toString() ?: 'all',
            'account_id' => $request->integer('account_id') ?: null,
            'search' => $request->string('search')->toString() ?: null,
        ];
    }
}
