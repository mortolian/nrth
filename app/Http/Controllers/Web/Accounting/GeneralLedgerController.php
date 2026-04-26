<?php

namespace App\Http\Controllers\Web\Accounting;

use App\Domain\Accounting\Models\Account;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class GeneralLedgerController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! Schema::hasTable('accounts')) {
            return Inertia::render('Accounting/Journal/Index', [
                'groups' => [],
                'period' => ['from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString()],
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;

        $from = $request->string('from')->toString() ?: now()->startOfYear()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();

        // Aggregate debits and credits within the period for active accounts.
        $activity = DB::table('journal_entries')
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->where('transactions.team_id', $teamId)
            ->whereIn('transactions.status', ['posted', 'void'])
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->select([
                'journal_entries.account_id',
                DB::raw("SUM(CASE WHEN journal_entries.type = 'debit' THEN journal_entries.amount_cents ELSE 0 END) as period_debits"),
                DB::raw("SUM(CASE WHEN journal_entries.type = 'credit' THEN journal_entries.amount_cents ELSE 0 END) as period_credits"),
            ])
            ->groupBy('journal_entries.account_id')
            ->get()
            ->keyBy('account_id');

        // Opening balances: everything posted/voided before the period start.
        $opening = DB::table('journal_entries')
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->where('transactions.team_id', $teamId)
            ->whereIn('transactions.status', ['posted', 'void'])
            ->whereDate('transactions.transaction_date', '<', $from)
            ->select([
                'journal_entries.account_id',
                DB::raw("SUM(CASE WHEN journal_entries.type = 'debit' THEN journal_entries.amount_cents ELSE 0 END) as debit_sum"),
                DB::raw("SUM(CASE WHEN journal_entries.type = 'credit' THEN journal_entries.amount_cents ELSE 0 END) as credit_sum"),
            ])
            ->groupBy('journal_entries.account_id')
            ->get()
            ->keyBy('account_id');

        $accounts = Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $typeOrder = ['asset', 'liability', 'equity', 'income', 'expense'];

        $groups = $accounts
            ->groupBy(fn (Account $a) => $a->type->value)
            ->sortBy(fn ($_, $type) => array_search($type, $typeOrder, true))
            ->map(fn ($group, $type) => [
                'type' => $type,
                'accounts' => $group->map(function (Account $a) use ($activity, $opening) {
                    return [
                        'id' => $a->id,
                        'code' => $a->code,
                        'name' => $a->name,
                        'normal_balance' => $a->type->normalBalance(),
                        'opening_balance_cents' => $this->netBalance($a, $opening->get($a->id)),
                        'period_debits_cents' => (int) ($activity->get($a->id)?->period_debits ?? 0),
                        'period_credits_cents' => (int) ($activity->get($a->id)?->period_credits ?? 0),
                        'closing_balance_cents' => $this->closingBalance($a, $opening->get($a->id), $activity->get($a->id)),
                        'statement_url' => route('accounting.accounts.statement', $a->id),
                    ];
                })->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('Accounting/Journal/Index', [
            'groups' => $groups,
            'period' => ['from' => $from, 'to' => $to],
        ]);
    }

    private function netBalance(Account $account, ?object $row): int
    {
        if ($row === null) {
            return 0;
        }

        $debit = (int) $row->debit_sum;
        $credit = (int) $row->credit_sum;

        return $account->type->isDebit() ? $debit - $credit : $credit - $debit;
    }

    private function closingBalance(Account $account, ?object $openingRow, ?object $activityRow): int
    {
        $openingDebit = (int) ($openingRow?->debit_sum ?? 0);
        $openingCredit = (int) ($openingRow?->credit_sum ?? 0);
        $periodDebit = (int) ($activityRow?->period_debits ?? 0);
        $periodCredit = (int) ($activityRow?->period_credits ?? 0);

        $totalDebit = $openingDebit + $periodDebit;
        $totalCredit = $openingCredit + $periodCredit;

        return $account->type->isDebit() ? $totalDebit - $totalCredit : $totalCredit - $totalDebit;
    }
}
