<?php

namespace Tests\Feature\Settings;

use App\Models\TeamBankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyBankAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_accounts_for_invoice_pdf_respects_show_on_invoice(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $this->assertNotNull($team);

        TeamBankAccount::query()->create([
            'team_id' => $team->id,
            'sort_order' => 0,
            'bank_name' => 'Bank A',
            'bank_account_holder' => null,
            'bank_account_number' => '111',
            'swift_code' => 'SBZAZAJJ',
            'bic' => 'DEUTDEFF',
            'iban' => 'GB29NWBK60161331926819',
            'routing_sort_code' => '20-00-00',
            'bank_branch_code' => null,
            'bank_account_type' => 'current',
            'title' => 'Primary operating account',
            'show_on_invoice' => true,
        ]);
        TeamBankAccount::query()->create([
            'team_id' => $team->id,
            'sort_order' => 1,
            'bank_name' => 'Bank B',
            'bank_account_holder' => null,
            'bank_account_number' => '222',
            'bank_branch_code' => null,
            'bank_account_type' => 'savings',
            'show_on_invoice' => false,
        ]);

        $shown = $team->fresh()->bankAccountsForInvoicePdf();
        $this->assertCount(1, $shown);
        $this->assertSame('Bank A', $shown[0]['name']);
        $this->assertSame('111', $shown[0]['account']);
        $this->assertSame('SBZAZAJJ', $shown[0]['swift_code']);
        $this->assertSame('DEUTDEFF', $shown[0]['bic']);
        $this->assertSame('GB29NWBK60161331926819', $shown[0]['iban']);
        $this->assertSame('20-00-00', $shown[0]['routing_sort_code']);
        $this->assertSame('Primary operating account', $shown[0]['title']);
    }

    public function test_legacy_company_settings_bank_used_when_no_table_rows(): void
    {
        $team = User::factory()->withPersonalTeam()->create()->currentTeam;
        $this->assertNotNull($team);

        $team->company_settings = array_replace_recursive(
            $team->company_settings ?? [],
            [
                'bank_name' => 'Legacy Bank',
                'bank_account_number' => '999',
            ]
        );
        $team->save();

        $shown = $team->fresh()->bankAccountsForInvoicePdf();
        $this->assertCount(1, $shown);
        $this->assertSame('Legacy Bank', $shown[0]['name']);
        $this->assertSame('999', $shown[0]['account']);
    }
}
