<?php

namespace App\Http\Controllers\Web\Banking;

use App\Domain\Banking\Actions\CreateBankingAccountAction;
use App\Domain\Banking\Models\BankingAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankingAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = BankingAccount::query()
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
            ]);

        return Inertia::render('Banking/Accounts/Index', [
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request, CreateBankingAccountAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number_last4' => ['nullable', 'string', 'size:4'],
            'currency' => ['nullable', 'string', 'size:3'],
            'type' => ['nullable', 'string', 'max:50'],
        ]);

        $action->execute([
            'team_id' => (int) $request->user()->current_team_id,
            'name' => $validated['name'],
            'bank_name' => $validated['bank_name'] ?? null,
            'account_number_last4' => $validated['account_number_last4'] ?? null,
            'currency' => $validated['currency'] ?? 'ZAR',
            'type' => $validated['type'] ?? null,
        ]);

        return redirect()
            ->route('banking.accounts.index')
            ->with('success', __('Import account created.'));
    }
}
