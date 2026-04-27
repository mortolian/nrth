<?php

namespace Tests\Feature\Domain\Invoicing;

use App\Domain\Accounting\Actions\PostTransactionAction;
use App\Domain\Accounting\Actions\VoidTransactionAction;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\EntryType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Accounting\Services\LedgerService;
use App\Domain\Invoicing\Actions\CreateInvoiceAction;
use App\Domain\Invoicing\Actions\RecordPaymentAction;
use App\Domain\Invoicing\Actions\SendInvoiceAction;
use App\Domain\Invoicing\Actions\VoidInvoiceAction;
use App\Domain\Invoicing\DTOs\CreateInvoiceDTO;
use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePdfService;
use App\Mail\InvoiceMailer;
use App\Domain\Invoicing\Services\InvoiceNumberService;
use App\Domain\Tax\Models\TaxRate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class InvoicingActionsTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_create_invoice_action_creates_invoice_and_line_items(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);
        $client = Client::factory()->for($team)->create();

        $taxRate = TaxRate::factory()->for($team)->create([
            'is_default' => true,
            'rate' => 0.15,
        ]);
        $team->forceFill([
            'company_settings' => array_replace($team->company_settings ?? [], [
                'vat_registered' => true,
                'default_tax_rate_id' => $taxRate->id,
            ]),
        ])->save();

        $dto = new CreateInvoiceDTO(
            teamId: $team->id,
            clientId: $client->id,
            issueDate: '2026-04-25',
            lineItems: [
                ['description' => 'Consulting', 'quantity' => 2, 'unit_price_cents' => 50_00, 'vat_rate' => 0.15],
                ['description' => 'Zero-rated item', 'quantity' => 1, 'unit_price_cents' => 10_00, 'vat_rate' => 0.0],
            ],
        );

        $invoice = (new CreateInvoiceAction(new InvoiceNumberService))->execute($dto);

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame('INV-2026-0001', $invoice->number);
        $this->assertCount(2, $invoice->lineItems);
        $this->assertSame(110_00, (int) $invoice->getRawOriginal('subtotal_cents'));
        $this->assertSame(15_00, (int) $invoice->getRawOriginal('vat_amount_cents'));
        $this->assertSame(125_00, (int) $invoice->getRawOriginal('total_cents'));
    }

    public function test_send_invoice_action_marks_invoice_as_sent(): void
    {
        Mail::fake();
        $invoice = Invoice::factory()->create();
        $tmp = storage_path('app/testing-invoice-'.$invoice->id.'.pdf');
        File::put($tmp, 'fake pdf');
        $media = $invoice->addMedia($tmp)->usingFileName('test.pdf')->toMediaCollection('invoice-pdfs');
        File::delete($tmp);

        $pdfService = Mockery::mock(InvoicePdfService::class);
        $pdfService->shouldReceive('generate')->once()->andReturn($media);

        $sent = (new SendInvoiceAction($pdfService))->execute($invoice);

        $this->assertSame(InvoiceStatus::Sent, $sent->status);
        $this->assertNotNull($sent->sent_at);
        Mail::assertQueued(InvoiceMailer::class);
    }

    public function test_record_payment_action_creates_payment_and_posts_transaction(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        Account::factory()->for($team)->create(['code' => '1010', 'type' => AccountType::Asset, 'is_system' => true]);
        Account::factory()->for($team)->create(['code' => '1100', 'type' => AccountType::Asset, 'is_system' => true]);
        Account::factory()->for($team)->create(['code' => '2100', 'type' => AccountType::Liability, 'is_system' => true]);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'subtotal_cents' => 100_00,
                'vat_amount_cents' => 15_00,
                'total_cents' => 115_00,
                'amount_paid_cents' => 0,
            ]);

        $action = new RecordPaymentAction(
            new PostTransactionAction(new LedgerService)
        );

        $payment = $action->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: $team->id,
            amountCents: 115_00,
            paymentDate: '2026-04-25',
            method: PaymentMethod::Eft,
            createdBy: $user->id,
        ));

        $this->assertNotNull($payment->transaction_id);
        $this->assertSame(TransactionStatus::Posted, $payment->transaction?->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_void_invoice_action_voids_linked_posted_transactions(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);
        $client = Client::factory()->for($team)->create();

        $asset = Account::factory()->for($team)->asset()->create(['code' => '9091']);
        $income = Account::factory()->for($team)->income()->create(['code' => '9092']);

        $transaction = Transaction::query()->create([
            'team_id' => $team->id,
            'type' => TransactionType::Payment,
            'status' => TransactionStatus::Draft,
            'transaction_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $asset->id,
            'type' => EntryType::Debit,
            'amount_cents' => 10_000,
            'currency' => 'ZAR',
        ]);
        JournalEntry::query()->create([
            'transaction_id' => $transaction->id,
            'account_id' => $income->id,
            'type' => EntryType::Credit,
            'amount_cents' => 10_000,
            'currency' => 'ZAR',
        ]);

        (new PostTransactionAction(new LedgerService))->execute($transaction);

        $invoice = Invoice::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'status' => InvoiceStatus::Sent,
            'number' => 'INV-2026-9001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal_cents' => 100_00,
            'vat_amount_cents' => 15_00,
            'total_cents' => 115_00,
            'amount_paid_cents' => 0,
            'currency' => 'ZAR',
        ]);

        $invoice->payments()->create([
            'team_id' => $team->id,
            'amount_cents' => 100_00,
            'currency' => 'ZAR',
            'payment_date' => now()->toDateString(),
            'method' => PaymentMethod::Eft,
            'transaction_id' => $transaction->id,
        ]);

        $voidAction = new VoidInvoiceAction(
            new VoidTransactionAction(new PostTransactionAction(new LedgerService))
        );
        $voided = $voidAction->execute($invoice->fresh(), 'Cancelled');

        $this->assertSame(InvoiceStatus::Void, $voided->status);
        $this->assertSame(TransactionStatus::Void, $transaction->fresh()->status);
    }
}
