<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\Services\PaymentReceiptPdfService;
use App\Mail\PaymentReceiptMailer;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_receipt_preview_streams_inline(): void
    {
        [$owner, $invoice, $payment] = $this->seedPaidInvoice();

        $tmp = storage_path('app/testing-receipt-'.$payment->id.'.pdf');
        File::put($tmp, '%PDF-1.4 receipt fixture');
        $media = $payment->addMedia($tmp)->usingFileName('receipt-test.pdf')->toMediaCollection('payment-receipts');
        File::delete($tmp);

        $pdfService = Mockery::mock(PaymentReceiptPdfService::class);
        $pdfService->shouldReceive('generate')->once()->andReturn($media);
        $this->app->instance(PaymentReceiptPdfService::class, $pdfService);

        $this->actingAs($owner)
            ->get(route('invoicing.invoices.payments.receipt.preview', [$invoice, $payment]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="receipt-test.pdf"');
    }

    public function test_payment_receipt_send_queues_mail(): void
    {
        Mail::fake();
        [$owner, $invoice, $payment] = $this->seedPaidInvoice();

        $tmp = storage_path('app/testing-receipt-send-'.$payment->id.'.pdf');
        File::put($tmp, '%PDF-1.4 receipt fixture');
        $media = $payment->addMedia($tmp)->usingFileName('receipt-send.pdf')->toMediaCollection('payment-receipts');
        File::delete($tmp);

        $pdfService = Mockery::mock(PaymentReceiptPdfService::class);
        $pdfService->shouldReceive('generate')->once()->andReturn($media);
        $this->app->instance(PaymentReceiptPdfService::class, $pdfService);

        $this->actingAs($owner)
            ->post(route('invoicing.invoices.payments.receipt.send', [$invoice, $payment]))
            ->assertRedirect();

        Mail::assertQueued(PaymentReceiptMailer::class, function (PaymentReceiptMailer $mail) use ($payment, $media): bool {
            return $mail->payment->is($payment) && $mail->pdfMediaId === $media->id;
        });
    }

    public function test_totals_as_of_payment_include_prior_payments_only(): void
    {
        [$owner, $invoice] = $this->seedPaidInvoice(createPayment: false);
        unset($owner);

        $first = Payment::factory()->for($invoice->team)->for($invoice)->create([
            'amount_cents' => 4000,
            'payment_date' => '2026-07-01',
            'method' => PaymentMethod::Eft,
        ]);
        $second = Payment::factory()->for($invoice->team)->for($invoice)->create([
            'amount_cents' => 2500,
            'payment_date' => '2026-07-10',
            'method' => PaymentMethod::Cash,
        ]);

        $invoice->forceFill([
            'total_cents' => 10_000,
            'amount_paid_cents' => 6500,
        ])->save();

        $service = app(PaymentReceiptPdfService::class);
        $firstTotals = $service->totalsAsOfPayment($first->fresh(['invoice']));
        $secondTotals = $service->totalsAsOfPayment($second->fresh(['invoice']));

        $this->assertSame(4000, $firstTotals['paid_through_cents']);
        $this->assertSame(6000, $firstTotals['outstanding_cents']);
        $this->assertSame(6500, $secondTotals['paid_through_cents']);
        $this->assertSame(3500, $secondTotals['outstanding_cents']);
    }

    /**
     * @return array{0: User, 1: Invoice, 2?: Payment}
     */
    private function seedPaidInvoice(bool $createPayment = true): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $team = $owner->currentTeam;
        $client = Client::factory()->for($team)->create([
            'email' => 'client@example.com',
        ]);
        $invoice = Invoice::factory()->for($team)->for($client)->create([
            'number' => 'INV-RCPT-1',
            'total_cents' => 10_000,
            'amount_paid_cents' => $createPayment ? 2500 : 0,
        ]);

        if (! $createPayment) {
            return [$owner, $invoice];
        }

        $payment = Payment::factory()->for($team)->for($invoice)->create([
            'amount_cents' => 2500,
            'payment_date' => '2026-07-15',
            'method' => PaymentMethod::Eft,
            'currency' => 'ZAR',
        ]);

        return [$owner, $invoice, $payment];
    }
}
