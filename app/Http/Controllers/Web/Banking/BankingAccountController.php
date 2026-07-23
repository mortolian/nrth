<?php

namespace App\Http\Controllers\Web\Banking;

use App\Domain\Banking\Actions\CreateBankingAccountAction;
use App\Domain\Banking\Actions\UpdateBankingAccountAction;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Support\BankingPaymentAccounts;
use App\Http\Controllers\Controller;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BankingAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()?->currentTeam;
        abort_if($team === null, 403);
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);

        $accounts = BankingAccount::query()
            ->with('glAccount:id,code,name')
            ->orderBy('name')
            ->get()
            ->map(fn (BankingAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'bank_name' => $account->bank_name,
                'account_number_last4' => $account->account_number_last4,
                'currency' => $account->currency,
                'type' => $account->type,
                'is_active' => $account->is_active,
                'gl_account_id' => $account->gl_account_id,
                'gl_label' => $account->glAccount
                    ? trim($account->glAccount->code.' - '.$account->glAccount->name)
                    : null,
            ]);

        return Inertia::render('Banking/Accounts/Index', [
            'accounts' => $accounts,
            'gl_options' => BankingPaymentAccounts::linkableGlOptions((int) $team->id),
        ]);
    }

    public function store(Request $request, CreateBankingAccountAction $action): RedirectResponse
    {
        $teamId = (int) $request->user()->current_team_id;
        $validated = $this->validatedPayload($request, $teamId);

        $action->execute([
            'team_id' => $teamId,
            'name' => $validated['name'],
            'bank_name' => $validated['bank_name'] ?? null,
            'account_number_last4' => $validated['account_number_last4'] ?? null,
            'currency' => $validated['currency'] ?? 'ZAR',
            'type' => $validated['type'] ?? null,
            'gl_account_id' => (int) $validated['gl_account_id'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return redirect()
            ->route('banking.accounts.index')
            ->with('success', __('Banking account created.'));
    }

    public function update(
        Request $request,
        BankingAccount $bankingAccount,
        UpdateBankingAccountAction $action,
    ): RedirectResponse {
        abort_unless($bankingAccount->team_id === (int) $request->user()->current_team_id, 403);

        $teamId = (int) $request->user()->current_team_id;
        $validated = $this->validatedPayload($request, $teamId);

        $action->execute($bankingAccount, [
            'name' => $validated['name'],
            'bank_name' => $validated['bank_name'] ?? null,
            'account_number_last4' => $validated['account_number_last4'] ?? null,
            'currency' => $validated['currency'] ?? 'ZAR',
            'type' => $validated['type'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'gl_account_id' => (int) $validated['gl_account_id'],
        ]);

        return redirect()
            ->route('banking.accounts.index')
            ->with('success', __('Banking account updated.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, int $teamId): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number_last4' => ['nullable', 'string', 'size:4'],
            'currency' => ['nullable', 'string', 'size:3'],
            'type' => ['nullable', 'string', 'max:50'],
            'gl_account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($q) => $q->where('team_id', $teamId)->where('is_active', true)),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return $request->validate($rules);
    }
}
