<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePdfService;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class InvoicePdfPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_pdf_preview_streams_inline(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $team = $owner->currentTeam;
        $client = Client::factory()->for($team)->create();
        $invoice = Invoice::factory()->for($team)->for($client)->create([
            'number' => 'INV-PREVIEW-1',
        ]);

        $tmp = storage_path('app/testing-invoice-preview-'.$invoice->id.'.pdf');
        File::put($tmp, '%PDF-1.4 preview fixture');
        $media = $invoice->addMedia($tmp)->usingFileName('INV-PREVIEW-1.pdf')->toMediaCollection('invoice-pdfs');
        File::delete($tmp);

        $pdfService = Mockery::mock(InvoicePdfService::class);
        $pdfService->shouldReceive('generate')->once()->andReturn($media);
        $this->app->instance(InvoicePdfService::class, $pdfService);

        $this->actingAs($owner)
            ->get(route('invoices.pdf.preview', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="INV-PREVIEW-1.pdf"');
    }
}
