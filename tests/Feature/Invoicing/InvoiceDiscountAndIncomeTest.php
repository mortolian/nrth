<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Invoicing\Actions\MarkInvoiceSentAction;
use App\Domain\Invoicing\Actions\PostInvoiceAccrualAction;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLineItem;
use App\Domain\Tax\Models\TaxRate;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDiscountAndIncomeTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    private function enableVat(Team $team): void
    {
        $taxRate = TaxRate::factory()->for($team)->create([
            'name' => 'Standard',
            'rate' => 0.15,
            'is_default' => true,
            'is_active' => true,
        ]);
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                [
                    'vat_registered' => true,
                    'default_tax_rate_id' => $taxRate->id,
                ],
            ),
        ])->save();
    }

    public function test_store_invoice_with_line_and_document_discounts(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $this->enableVat($owner->currentTeam);
        $client = Client::factory()->for($owner->currentTeam)->create();

        $this->actingAs($owner)
            ->post(route('invoicing.invoices.store'), [
                'client_id' => $client->id,
                'number' => 'INV-DISC-1',
                'issue_date' => '2026-07-01',
                'due_date' => '2026-07-31',
                'currency' => 'ZAR',
                'discount_type' => 'percent',
                'discount_percent' => 10,
                'line_items' => [
                    [
                        'description' => 'Work',
                        'quantity' => 1,
                        'unit_price_cents' => 10000,
                        'vat_rate' => 0.15,
                        'discount_type' => 'fixed',
                        'discount_cents' => 1000,
                    ],
                ],
            ])
            ->assertRedirect();

        $invoice = Invoice::queryWithoutTeamScope()->where('number', 'INV-DISC-1')->first();
        $this->assertNotNull($invoice);
        // Line excl after fixed 1000 = 9000; doc 10% = 900; taxable 8100; vat 1215; total 9315
        $this->assertSame(8100, (int) $invoice->getRawOriginal('subtotal_cents'));
        $this->assertSame(1215, (int) $invoice->getRawOriginal('vat_amount_cents'));
        $this->assertSame(9315, (int) $invoice->getRawOriginal('total_cents'));
        $this->assertSame(1900, (int) $invoice->getRawOriginal('discount_total_cents'));
    }

    public function test_duplicate_create_prefills_from_source(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $client = Client::factory()->for($owner->currentTeam)->create();
        $invoice = Invoice::factory()->for($owner->currentTeam)->create([
            'client_id' => $client->id,
            'status' => InvoiceStatus::Sent,
            'notes' => 'Keep me',
            'discount_type' => 'percent',
            'discount_percent' => 5,
        ]);
        InvoiceLineItem::factory()->for($invoice)->create([
            'description' => 'Copied line',
            'quantity' => 2,
            'unit_price_cents' => 2500,
            'discount_type' => 'percent',
            'discount_percent' => 10,
        ]);

        $this->actingAs($owner)
            ->get(route('invoicing.invoices.create', ['from' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Invoicing/Invoices/Form')
                ->where('isEditing', false)
                ->where('invoice.client_id', $client->id)
                ->where('invoice.notes', 'Keep me')
                ->where('invoice.discount_type', 'percent')
                ->has('invoice.line_items', 1)
                ->where('invoice.line_items.0.description', 'Copied line'));
    }

    public function test_mark_sent_posts_accrual_to_income_accounts(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $team = $owner->currentTeam;
        (new DefaultChartOfAccountsSeeder)->ensureForTeam($team);

        $defaultIncome = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('code', '4000')
            ->firstOrFail();
        $overrideIncome = Account::factory()->for($team)->create([
            'type' => AccountType::Income,
            'code' => '4100',
            'name' => 'Product Sales',
            'is_active' => true,
        ]);

        $invoice = Invoice::factory()->for($team)->create([
            'status' => InvoiceStatus::Draft,
            'income_account_id' => $defaultIncome->id,
            'subtotal_cents' => 10000,
            'vat_amount_cents' => 1500,
            'total_cents' => 11500,
            'amount_paid_cents' => 0,
        ]);
        InvoiceLineItem::factory()->for($invoice)->create([
            'description' => 'Default income',
            'quantity' => 1,
            'unit_price_cents' => 6000,
            'vat_rate' => 0.15,
            'vat_amount_cents' => 900,
            'total_cents' => 6900,
            'income_account_id' => null,
        ]);
        InvoiceLineItem::factory()->for($invoice)->create([
            'description' => 'Override income',
            'quantity' => 1,
            'unit_price_cents' => 4000,
            'vat_rate' => 0.15,
            'vat_amount_cents' => 600,
            'total_cents' => 4600,
            'income_account_id' => $overrideIncome->id,
        ]);

        $this->actingTeamContext($owner, $team);
        app(MarkInvoiceSentAction::class)->execute($invoice->fresh());

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertNotNull($invoice->accrual_transaction_id);

        $credits = JournalEntry::query()
            ->where('transaction_id', $invoice->accrual_transaction_id)
            ->where('type', EntryType::Credit)
            ->get();

        $this->assertTrue($credits->contains(
            fn (JournalEntry $e) => (int) $e->account_id === (int) $defaultIncome->id
                && (int) $e->getRawOriginal('amount_cents') === 6000
        ));
        $this->assertTrue($credits->contains(
            fn (JournalEntry $e) => (int) $e->account_id === (int) $overrideIncome->id
                && (int) $e->getRawOriginal('amount_cents') === 4000
        ));

        // Idempotent
        app(PostInvoiceAccrualAction::class)->execute($invoice->fresh());
        $this->assertSame(
            1,
            Invoice::queryWithoutTeamScope()->whereKey($invoice->id)->whereNotNull('accrual_transaction_id')->count()
        );
        $this->assertSame(
            (int) $invoice->accrual_transaction_id,
            (int) $invoice->fresh()->accrual_transaction_id
        );
    }
}
