<?php

namespace App\Http\Controllers\Web\Accounting;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class AccountStatementController extends Controller
{
    public function __invoke(Request $request, Account $account): Response
    {
        abort_unless($account->team_id === $request->user()->current_team_id, 403);

        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: now()->endOfMonth()->toDateString();
        $perPage = 25;
        $page = max(1, (int) $request->integer('page', 1));

        $baseQuery = JournalEntry::query()
            ->where('account_id', $account->id)
            ->whereHas('transaction', fn ($q) => $q->where('team_id', $account->team_id))
            ->with('transaction:id,reference,description,transaction_date');

        $openingEntries = (clone $baseQuery)
            ->whereHas('transaction', fn ($q) => $q->whereDate('transaction_date', '<', $from))
            ->orderBy('id')
            ->get();

        $openingBalance = $openingEntries->sum(fn (JournalEntry $entry): int => $this->signedAmount($entry, $account));

        $periodEntries = (clone $baseQuery)
            ->whereHas('transaction', fn ($q) => $q->whereBetween('transaction_date', [$from, $to]))
            ->get()
            ->sortBy(fn (JournalEntry $entry) => sprintf(
                '%s-%010d',
                optional($entry->transaction?->transaction_date)->toDateString() ?? '0000-00-00',
                $entry->id
            ))
            ->values();

        $running = $openingBalance;
        $mapped = $periodEntries->map(function (JournalEntry $entry) use ($account, &$running): array {
            $amount = (int) $entry->getRawOriginal('amount_cents');
            $debit = $entry->type->value === 'debit' ? $amount : 0;
            $credit = $entry->type->value === 'credit' ? $amount : 0;
            $running += $this->signedAmount($entry, $account);

            return [
                'id' => $entry->id,
                'date' => optional($entry->transaction?->transaction_date)->toDateString(),
                'reference' => $entry->transaction?->reference,
                'description' => $entry->description ?: $entry->transaction?->description,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
                'is_normal_balance' => $running >= 0,
            ];
        });

        $total = $mapped->count();
        $offset = ($page - 1) * $perPage;
        $pageItems = $mapped->slice($offset, $perPage)->values();
        $entries = new LengthAwarePaginator(
            $pageItems,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $totalDebits = (int) $mapped->sum('debit');
        $totalCredits = (int) $mapped->sum('credit');
        $closingBalance = $mapped->isNotEmpty()
            ? (int) ($mapped->last()['running_balance'] ?? $openingBalance)
            : $openingBalance;

        return Inertia::render('Accounting/Accounts/Statement', [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'normal_balance' => $account->type->normalBalance(),
            ],
            'entries' => $entries,
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'totals' => [
                'debits' => $totalDebits,
                'credits' => $totalCredits,
            ],
        ]);
    }

    private function signedAmount(JournalEntry $entry, Account $account): int
    {
        $amount = (int) $entry->getRawOriginal('amount_cents');
        $isNormalType = $entry->type->value === $account->type->normalBalance();

        return $isNormalType ? $amount : -$amount;
    }
}
