<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\User;
use App\Support\InvoicePayQrCode;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePdfPayQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_pdf_blade_includes_embedded_qr_when_pay_link_props_provided(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => 'd4e5f6789012345678abcdef01234567',
            ]);

        $invoice->load(['team', 'client', 'lineItems']);

        $publicPayUrl = route('public.invoice.pay', ['token' => $invoice->public_token], true);
        $publicPayQrDataUri = InvoicePayQrCode::pngDataUri($publicPayUrl, 168, 8);

        $html = view('pdf.invoice', [
            'invoice' => $invoice,
            'public_pay_url' => $publicPayUrl,
            'public_pay_qr_data_uri' => $publicPayQrDataUri,
        ])->render();

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString($publicPayUrl, $html);
        $this->assertStringContainsString('Pay online', $html);
    }

    public function test_invoice_pdf_blade_omits_pay_qr_when_no_data_uri(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $invoice = Invoice::factory()
            ->for($team)
            ->create([
                'status' => InvoiceStatus::Sent,
                'sent_at' => Carbon::parse('2026-04-15'),
                'public_token' => null,
            ]);

        $invoice->load(['team', 'client', 'lineItems']);

        $html = view('pdf.invoice', [
            'invoice' => $invoice,
            'public_pay_url' => null,
            'public_pay_qr_data_uri' => null,
        ])->render();

        $this->assertStringNotContainsString('pay-online-qr', $html);
        $this->assertStringNotContainsString('data:image/png;base64,', $html);
    }
}
