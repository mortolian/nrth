<?php

namespace App\Http\Controllers\Web\Banking;

use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class BankingTransactionController extends Controller
{
    public function index(Request $request): Response
    {
        if (! Schema::hasTable('banking_transactions')) {
            return Inertia::render('Banking/Transactions/Index', [
                'transactions' => new LengthAwarePaginator([], 0, 25),
                'accounts' => [],
                'filters' => $this->filters($request),
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;
        $from = (string) $request->string('from')->toString();
        $to = (string) $request->string('to')->toString();
        $accountId = (int) $request->integer('account_id');
        $direction = (string) $request->string('direction')->toString();
        $search = trim((string) $request->string('search')->toString());

        $query = BankingTransaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->with([
                'account:id,name,bank_name,currency',
                'import:id,original_filename,created_at',
            ]);

        if ($from !== '') {
            $query->whereDate('transaction_date', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('transaction_date', '<=', $to);
        }
        if ($accountId > 0) {
            $query->where('account_id', $accountId);
        }
        if ($direction !== '' && in_array($direction, array_map(fn (TransactionDirection $d) => $d->value, TransactionDirection::cases()), true)) {
            $query->where('direction', $direction);
        }
        if ($search !== '') {
            $pattern = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($pattern): void {
                $q->whereRaw('LOWER(description) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(reference) LIKE ?', [$pattern]);
            });
        }

        $transactions = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (BankingTransaction $transaction): array => [
                'id' => $transaction->id,
                'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
                'value_date' => $transaction->value_date?->format('Y-m-d'),
                'description' => $transaction->description,
                'reference' => $transaction->reference,
                'amount' => (string) $transaction->amount,
                'currency' => $transaction->currency,
                'direction' => $transaction->direction->value,
                'running_balance' => $transaction->running_balance !== null ? (string) $transaction->running_balance : null,
                'account' => [
                    'id' => $transaction->account->id,
                    'name' => $transaction->account->name,
                    'bank_name' => $transaction->account->bank_name,
                ],
                'import' => $transaction->import !== null ? [
                    'id' => $transaction->import->id,
                    'original_filename' => $transaction->import->original_filename,
                    'imported_at' => $transaction->import->created_at?->format('Y-m-d H:i'),
                ] : null,
            ]);

        $accounts = BankingAccount::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->get(['id', 'name', 'bank_name', 'currency'])
            ->map(fn (BankingAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'bank_name' => $account->bank_name,
                'currency' => $account->currency,
            ])
            ->all();

        return Inertia::render('Banking/Transactions/Index', [
            'transactions' => $transactions,
            'accounts' => $accounts,
            'filters' => $this->filters($request),
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
            'account_id' => $request->integer('account_id') ?: null,
            'direction' => $request->string('direction')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
        ];
    }
}
