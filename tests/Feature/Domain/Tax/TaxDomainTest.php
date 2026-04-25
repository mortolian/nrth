<?php

namespace Tests\Feature\Domain\Tax;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TaxLineType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\TaxLine;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tax\Actions\CreateTaxPeriodAction;
use App\Domain\Tax\Actions\GenerateVATReturnAction;
use App\Domain\Tax\Enums\TaxPeriodStatus;
use App\Domain\Tax\Enums\TaxPeriodType;
use App\Domain\Tax\Models\TaxPeriod;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Services\ProvisionalTaxService;
use App\Domain\Tax\Services\VATService;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxDomainTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_create_tax_period_action_creates_vat_and_provisional_periods(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        $action = new CreateTaxPeriodAction(new ProvisionalTaxService);
        $result = $action->execute($team->id, 2026);

        $this->assertCount(6, $result['vat']);
        $this->assertCount(2, $result['provisional']);
        $this->assertSame(
            8,
            TaxPeriod::queryWithoutTeamScope()->where('team_id', $team->id)->count()
        );
    }

    public function test_generate_vat_return_action_submits_period_with_output_and_input_vat(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create();
        Invoice::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Sent,
            'number' => 'INV-2026-0001',
            'issue_date' => '2026-03-15',
            'due_date' => '2026-04-15',
            'subtotal_cents' => 100_00,
            'vat_amount_cents' => 15_00,
            'total_cents' => 115_00,
            'amount_paid_cents' => 0,
            'currency' => 'ZAR',
        ]);

        $inputTaxRate = TaxRate::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'name' => 'VAT 15%',
            'code' => 'VAT15',
            'rate' => 0.1500,
            'rate_percent' => 15.00,
            'is_default' => true,
            'is_exempt' => false,
            'is_active' => true,
        ]);

        $expense = Account::factory()->for($team)->create(['type' => AccountType::Expense, 'code' => '8801']);
        $bank = Account::factory()->for($team)->create(['type' => AccountType::Asset, 'code' => '8802']);

        $txn = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'transaction_date' => '2026-03-20',
            'created_by' => $user->id,
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $txn->id,
            'account_id' => $expense->id,
            'type' => EntryType::Debit,
            'amount_cents' => 200_00,
            'currency' => 'ZAR',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $txn->id,
            'account_id' => $bank->id,
            'type' => EntryType::Credit,
            'amount_cents' => 200_00,
            'currency' => 'ZAR',
        ]);
        TaxLine::query()->create([
            'transaction_id' => $txn->id,
            'tax_rate_id' => $inputTaxRate->id,
            'taxable_amount_cents' => 100_00,
            'tax_amount_cents' => 10_00,
            'type' => TaxLineType::Input,
        ]);

        $period = TaxPeriod::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'period_start' => '2026-03-01',
            'period_end' => '2026-04-30',
            'type' => TaxPeriodType::VAT,
            'status' => TaxPeriodStatus::Open,
        ]);

        $vatReturn = (new GenerateVATReturnAction(new VATService))->execute($period->fresh());

        $this->assertSame(15_00, (int) $vatReturn->getRawOriginal('output_vat_cents'));
        $this->assertSame(10_00, (int) $vatReturn->getRawOriginal('input_vat_cents'));
        $this->assertSame(5_00, (int) $vatReturn->getRawOriginal('net_vat_cents'));
        $this->assertSame(TaxPeriodStatus::Submitted, $period->fresh()->status);
        $this->assertNotNull($period->fresh()->submitted_at);
    }

    public function test_provisional_tax_service_returns_sa_tax_windows(): void
    {
        $service = new ProvisionalTaxService;
        $periods = $service->getProvisionalPeriods(2026);

        $this->assertSame('2026-03-01', $periods[0]['start']->toDateString());
        $this->assertSame('2026-08-31', $periods[0]['end']->toDateString());
        $this->assertSame('2026-09-01', $periods[1]['start']->toDateString());
        $this->assertSame('2027-02-28', $periods[1]['end']->toDateString());
    }
}
