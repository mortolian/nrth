<?php

namespace Tests\Feature\TenantIsolation;

use App\Domain\Accounting\Models\Account;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankingIsolationTest extends TestCase
{
    use IsolatesTwoBusinesses;
    use RefreshDatabase;

    private const BANK_NAME = 'Isolation Bank Alpha';

    private const LINE_DESCRIPTION = 'Isolation bank line Alpha';

    private const LINE_REFERENCE = 'ISO-BANK-A-9911';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoBusinesses();
    }

    public function test_outsider_cannot_see_or_mutate_bank_accounts_and_lines(): void
    {
        $gl = Account::factory()->for($this->teamA)->asset()->create([
            'code' => '1010',
            'name' => 'Bank',
            'is_system' => true,
        ]);
        $account = BankingAccount::factory()->for($this->teamA)->create([
            'name' => self::BANK_NAME,
            'gl_account_id' => $gl->id,
        ]);
        $line = BankingTransaction::factory()->for($this->teamA)->create([
            'account_id' => $account->id,
            'description' => self::LINE_DESCRIPTION,
            'reference' => self::LINE_REFERENCE,
            'duplicate_key' => 'iso-bank-a-'.uniqid(),
        ]);

        $this->asOutsider();

        $this->get(route('banking.accounts.index'))->assertOk()->assertDontSee(self::BANK_NAME);
        $this->get(route('banking.transactions.index'))->assertOk()->assertDontSee(self::LINE_DESCRIPTION);
        $this->get(route('banking.reconciliation.index'))
            ->assertRedirect(route('banking.transactions.index'));

        $this->assertHiddenFromOtherTeam($this->put(route('banking.accounts.update', $account), [
            'name' => 'Hijacked',
            'currency' => 'ZAR',
            'is_active' => true,
            'gl_account_id' => $gl->id,
        ]));
        $this->assertHiddenFromOtherTeam($this->post(route('banking.reconciliation.exclude', $line)));
        $this->assertHiddenFromOtherTeam($this->post(route('banking.reconciliation.reset', $line)));
        $this->assertHiddenFromOtherTeam($this->post(route('banking.reconciliation.allocations.store', $line), [
            'allocations' => [],
        ]));

        $this->assertNotNull(BankingAccount::queryWithoutTeamScope()->find($account->id));
        $this->assertSame(self::BANK_NAME, BankingAccount::queryWithoutTeamScope()->find($account->id)?->name);
        $this->assertNotNull(BankingTransaction::queryWithoutTeamScope()->find($line->id));
    }
}
