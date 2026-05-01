<?php

namespace App\Http\Controllers\Web\Accounting;

use App\Domain\Accounting\Actions\CreateAccountAction;
use App\Domain\Accounting\Actions\DeactivateAccountAction;
use App\Domain\Accounting\Actions\UpdateAccountAction;
use App\Domain\Accounting\DTOs\CreateAccountDTO;
use App\Domain\Accounting\DTOs\Unspecified;
use App\Domain\Accounting\DTOs\UpdateAccountDTO;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function seedDefault(Request $request): RedirectResponse
    {
        $team = $this->currentTeam($request);
        abort_unless($request->user()->can('update', $team), 403);

        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);

        return redirect()
            ->route('accounting.accounts.index')
            ->with('success', __('Default chart of accounts has been installed.'));
    }

    public function create(Request $request): Response
    {
        $team = $this->currentTeam($request);
        abort_unless($request->user()->can('update', $team), 403);

        return Inertia::render('Accounting/Accounts/Form', [
            'isEditing' => false,
            'account' => null,
            'account_types' => $this->accountTypeOptions(),
            'parent_options' => $this->parentOptionsForTeam($team->id),
        ]);
    }

    public function store(Request $request, CreateAccountAction $createAccount): RedirectResponse
    {
        $team = $this->currentTeam($request);
        abort_unless($request->user()->can('update', $team), 403);

        $teamId = $team->id;

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
        ]);

        $createAccount->execute(new CreateAccountDTO(
            teamId: $teamId,
            code: trim($validated['code']),
            name: trim($validated['name']),
            type: AccountType::from($validated['type']),
            description: isset($validated['description']) ? trim((string) $validated['description']) : null,
            parentId: isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
            isSystem: false,
        ));

        return redirect()
            ->route('accounting.accounts.index')
            ->with('success', __('Account created.'));
    }

    public function edit(Request $request, Account $account): Response
    {
        $team = $this->currentTeam($request);
        abort_unless($request->user()->can('update', $team), 403);
        abort_unless($account->team_id === $team->id, 403);

        return Inertia::render('Accounting/Accounts/Form', [
            'isEditing' => true,
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'description' => $account->description,
                'type' => $account->type->value,
                'parent_id' => $account->parent_id,
                'is_system' => $account->is_system,
                'is_active' => $account->is_active,
            ],
            'account_types' => $this->accountTypeOptions(),
            'parent_options' => $this->parentOptionsForTeam($team->id, $account->id),
        ]);
    }

    public function update(Request $request, Account $account, UpdateAccountAction $updateAccount): RedirectResponse
    {
        $team = $this->currentTeam($request);
        abort_unless($request->user()->can('update', $team), 403);
        abort_unless($account->team_id === $team->id, 403);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($q) => $q->where('team_id', $team->id)),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $descriptionForDto = ($validated['description'] ?? null) === null || ($validated['description'] ?? '') === ''
            ? null
            : trim((string) $validated['description']);

        $parentForDto = Unspecified::Value;
        if (! $account->is_system) {
            $rawParent = $validated['parent_id'] ?? null;
            $parentForDto = ($rawParent === null || $rawParent === '') ? null : (int) $rawParent;
        }

        $isActiveForDto = Unspecified::Value;
        if (! $account->is_system && $request->has('is_active')) {
            $isActiveForDto = $request->boolean('is_active');
        }

        $updateAccount->execute($account, new UpdateAccountDTO(
            name: $account->is_system ? Unspecified::Value : trim($validated['name']),
            code: $account->is_system ? Unspecified::Value : trim($validated['code']),
            description: $descriptionForDto,
            parentId: $parentForDto,
            isActive: $isActiveForDto,
        ));

        return redirect()
            ->route('accounting.accounts.index')
            ->with('success', __('Account updated.'));
    }

    public function deactivate(Request $request, Account $account, DeactivateAccountAction $deactivate): RedirectResponse
    {
        $team = $this->currentTeam($request);
        abort_unless($request->user()->can('update', $team), 403);
        abort_unless($account->team_id === $team->id, 403);

        $deactivate->execute($account);

        return redirect()
            ->route('accounting.accounts.index')
            ->with('success', __('Account archived.'));
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        $team = $this->currentTeam($request);
        abort_unless($request->user()->can('update', $team), 403);
        abort_unless($account->team_id === $team->id, 403);

        if ($account->is_system) {
            abort(403);
        }

        if ($account->journalEntries()->exists()) {
            return redirect()
                ->route('accounting.accounts.index')
                ->withErrors(['account' => __('This account has ledger activity and cannot be deleted. Archive it instead.')]);
        }

        if ($account->children()->exists()) {
            return redirect()
                ->route('accounting.accounts.index')
                ->withErrors(['account' => __('Remove or reassign sub-accounts before deleting this account.')]);
        }

        $account->delete();

        return redirect()
            ->route('accounting.accounts.index')
            ->with('success', __('Account deleted.'));
    }

    private function currentTeam(Request $request): Team
    {
        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        return $team;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function accountTypeOptions(): array
    {
        return collect(AccountType::cases())->map(fn (AccountType $t): array => [
            'value' => $t->value,
            'label' => match ($t) {
                AccountType::Asset => __('Assets'),
                AccountType::Liability => __('Liabilities'),
                AccountType::Equity => __('Equity'),
                AccountType::Income => __('Income'),
                AccountType::Expense => __('Expenses'),
            },
        ])->values()->all();
    }

    /**
     * @return list<array{id: int, code: string, name: string, type: string}>
     */
    private function parentOptionsForTeam(int $teamId, ?int $excludeAccountId = null): array
    {
        return Account::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->when($excludeAccountId !== null, fn ($q) => $q->whereKeyNot($excludeAccountId))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(fn (Account $a): array => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type->value,
            ])
            ->all();
    }
}
