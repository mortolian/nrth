<?php

namespace App\Http\Controllers\Web\Accounting;

use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Transaction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

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
            ->with(['journalEntries.account']);

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
            $query->where(function ($q) use ($search): void {
                $q->where('reference', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
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

                return [
                    'id' => $transaction->id,
                    'date' => optional($transaction->transaction_date)->toDateString(),
                    'type' => $transaction->type->value,
                    'reference' => $transaction->reference,
                    'description' => $transaction->description,
                    'status' => $transaction->status->value,
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
