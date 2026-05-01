<?php

namespace App\Http\Controllers\Web\Accounting;

use App\Domain\Accounting\Models\Account;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class ChartOfAccountsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! Schema::hasTable('accounts')) {
            $user = $request->user();
            $team = $user->currentTeam;

            return Inertia::render('Accounting/Accounts/Index', [
                'groups' => [],
                'account_count' => 0,
                'can_manage' => $team !== null && $user->can('update', $team),
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;

        // Compute net balances for all accounts in one aggregated query.
        $balances = DB::table('journal_entries')
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->where('transactions.team_id', $teamId)
            ->whereIn('transactions.status', ['posted', 'void'])
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
            ->with('parent:id,code,name')
            ->orderBy('code')
            ->get();

        // Group by account type in a consistent display order.
        $typeOrder = ['asset', 'liability', 'equity', 'income', 'expense'];

        $groups = $accounts
            ->groupBy(fn (Account $a) => $a->type->value)
            ->sortBy(fn ($_, $type) => array_search($type, $typeOrder, true))
            ->map(fn ($group, $type) => [
                'type' => $type,
                'accounts' => $group->map(function (Account $a) use ($balances) {
                    return [
                        'id' => $a->id,
                        'code' => $a->code,
                        'name' => $a->name,
                        'description' => $a->description,
                        'type' => $a->type->value,
                        'normal_balance' => $a->type->normalBalance(),
                        'is_system' => $a->is_system,
                        'is_active' => $a->is_active,
                        'parent' => $a->parent
                            ? ['code' => $a->parent->code, 'name' => $a->parent->name]
                            : null,
                        'balance_cents' => $this->netBalance($a, $balances->get($a->id)),
                    ];
                })->values()->all(),
            ])
            ->values()
            ->all();

        $user = $request->user();
        $team = $user->currentTeam;

        return Inertia::render('Accounting/Accounts/Index', [
            'groups' => $groups,
            'account_count' => $accounts->count(),
            'can_manage' => $team !== null && $user->can('update', $team),
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
}
