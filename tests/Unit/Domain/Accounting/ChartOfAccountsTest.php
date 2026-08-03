<?php

namespace Tests\Unit\Domain\Accounting;

use App\Domain\Accounting\Actions\CreateAccountAction;
use App\Domain\Accounting\Actions\DeactivateAccountAction;
use App\Domain\Accounting\Actions\UpdateAccountAction;
use App\Domain\Accounting\DTOs\CreateAccountDTO;
use App\Domain\Accounting\DTOs\UpdateAccountDTO;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Exceptions\SystemAccountProtectedException;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Transaction;
use App\Models\Team;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChartOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_account_action_respects_team_and_parent_type(): void
    {
        $team = Team::factory()->create();
        $parent = Account::factory()->for($team)->asset()->create([
            'code' => '9001',
            'name' => 'Parent asset',
        ]);

        $action = new CreateAccountAction;
        $child = $action->execute(new CreateAccountDTO(
            teamId: $team->id,
            code: '9002',
            name: 'Child',
            type: AccountType::Asset,
            parentId: $parent->id,
        ));

        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame(AccountType::Asset, $child->type);
    }

    public function test_create_account_action_rejects_parent_type_mismatch(): void
    {
        $team = Team::factory()->create();
        $parent = Account::factory()->for($team)->asset()->create(['code' => '9003']);

        $this->expectException(ValidationException::class);

        (new CreateAccountAction)->execute(new CreateAccountDTO(
            teamId: $team->id,
            code: '9004',
            name: 'Wrong type',
            type: AccountType::Expense,
            parentId: $parent->id,
        ));
    }

    public function test_system_account_cannot_be_deleted_via_eloquent(): void
    {
        $account = Account::factory()->system()->create(['code' => 'SYS1']);

        $this->expectException(SystemAccountProtectedException::class);

        $account->delete();
    }

    public function test_system_account_code_cannot_change_via_eloquent(): void
    {
        $account = Account::factory()->system()->create(['code' => 'SYS2', 'name' => 'Locked']);

        $this->expectException(SystemAccountProtectedException::class);

        $account->update(['code' => 'HACK']);
    }

    public function test_system_account_name_can_be_updated_via_eloquent(): void
    {
        $account = Account::factory()->system()->create(['code' => 'SYS2B', 'name' => 'Bank']);

        $account->update(['name' => 'Cheque account']);

        $this->assertSame('Cheque account', $account->fresh()->name);
        $this->assertSame('SYS2B', $account->fresh()->code);
    }

    public function test_system_account_description_can_be_updated_via_action(): void
    {
        $account = Account::factory()->system()->create([
            'code' => 'SYS3',
            'name' => 'Locked',
            'description' => 'Old',
        ]);

        (new UpdateAccountAction)->execute($account, new UpdateAccountDTO(
            description: 'New narrative',
        ));

        $this->assertSame('New narrative', $account->fresh()->description);
    }

    public function test_update_account_action_allows_system_name_change(): void
    {
        $account = Account::factory()->system()->create(['code' => 'SYS4', 'name' => 'Bank']);

        (new UpdateAccountAction)->execute($account, new UpdateAccountDTO(
            name: 'Cheque account',
        ));

        $this->assertSame('Cheque account', $account->fresh()->name);
        $this->assertSame('SYS4', $account->fresh()->code);
    }

    public function test_update_account_action_blocks_system_code_change(): void
    {
        $account = Account::factory()->system()->create(['code' => 'SYS4B', 'name' => 'Bank']);

        $this->expectException(SystemAccountProtectedException::class);

        (new UpdateAccountAction)->execute($account, new UpdateAccountDTO(
            code: '9999',
        ));
    }

    public function test_update_account_action_allows_type_change_without_activity(): void
    {
        $account = Account::factory()->create([
            'code' => 'CUST-TYPE',
            'type' => AccountType::Expense,
            'is_system' => false,
            'parent_id' => null,
        ]);

        (new UpdateAccountAction)->execute($account, new UpdateAccountDTO(
            type: AccountType::Asset,
        ));

        $this->assertSame(AccountType::Asset, $account->fresh()->type);
    }

    public function test_update_account_action_blocks_type_change_with_journal_activity(): void
    {
        $team = Team::factory()->create();
        $account = Account::factory()->for($team)->create([
            'code' => 'CUST-ACT',
            'type' => AccountType::Expense,
            'is_system' => false,
        ]);
        $counter = Account::factory()->for($team)->asset()->create(['code' => 'CUST-BANK']);

        $txn = Transaction::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'type' => TransactionType::JournalAdjustment,
            'status' => TransactionStatus::Posted,
            'transaction_date' => now()->toDateString(),
            'posted_at' => now(),
            'description' => 'Test',
        ]);

        $txn->journalEntries()->create([
            'account_id' => $account->id,
            'type' => EntryType::Debit,
            'amount_cents' => 1000,
            'currency' => 'ZAR',
        ]);
        $txn->journalEntries()->create([
            'account_id' => $counter->id,
            'type' => EntryType::Credit,
            'amount_cents' => 1000,
            'currency' => 'ZAR',
        ]);

        $this->expectException(ValidationException::class);

        (new UpdateAccountAction)->execute($account, new UpdateAccountDTO(
            type: AccountType::Asset,
        ));
    }

    public function test_update_account_action_clears_type_mismatch_requires_matching_parent(): void
    {
        $team = Team::factory()->create();
        $parent = Account::factory()->for($team)->expense()->create(['code' => 'P1']);
        $account = Account::factory()->for($team)->expense()->create([
            'code' => 'C1',
            'parent_id' => $parent->id,
            'is_system' => false,
        ]);

        $this->expectException(ValidationException::class);

        (new UpdateAccountAction)->execute($account, new UpdateAccountDTO(
            type: AccountType::Asset,
        ));
    }

    public function test_update_account_action_can_change_type_and_clear_parent(): void
    {
        $team = Team::factory()->create();
        $parent = Account::factory()->for($team)->expense()->create(['code' => 'P2']);
        $account = Account::factory()->for($team)->expense()->create([
            'code' => 'C2',
            'parent_id' => $parent->id,
            'is_system' => false,
        ]);

        (new UpdateAccountAction)->execute($account, new UpdateAccountDTO(
            type: AccountType::Asset,
            parentId: null,
        ));

        $fresh = $account->fresh();
        $this->assertSame(AccountType::Asset, $fresh->type);
        $this->assertNull($fresh->parent_id);
    }

    public function test_deactivate_account_action_blocks_system_accounts(): void
    {
        $account = Account::factory()->system()->create(['code' => 'SYS5']);

        $this->expectException(SystemAccountProtectedException::class);

        (new DeactivateAccountAction)->execute($account);
    }

    public function test_deactivate_account_action_deactivates_custom_accounts(): void
    {
        $account = Account::factory()->create(['code' => 'CUST1', 'is_system' => false]);

        $fresh = (new DeactivateAccountAction)->execute($account);

        $this->assertFalse($fresh->is_active);
    }

    public function test_default_chart_seeder_sets_bank_cash_hierarchy(): void
    {
        $team = Team::factory()->create();

        (new DefaultChartOfAccountsSeeder)->runForTeam($team);

        $parent = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('code', '1000')
            ->firstOrFail();

        $this->assertNull($parent->parent_id);

        $bank = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('code', '1010')
            ->firstOrFail();

        $this->assertSame($parent->id, $bank->parent_id);
    }
}
