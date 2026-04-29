<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePdfService;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class InvoiceBulkPdfZipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_export_pdf_zip_returns_zip_when_invoices_belong_to_team(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        $client = Client::factory()->for($team)->create();
        $invoiceA = Invoice::factory()->for($team)->for($client)->create(['number' => 'INV-ZIP-A']);
        $invoiceB = Invoice::factory()->for($team)->for($client)->create(['number' => 'INV-ZIP-B']);

        $mock = Mockery::mock(InvoicePdfService::class);
        $mock->shouldReceive('renderToTemporaryPath')
            ->twice()
            ->andReturnUsing(function (Invoice $invoice) {
                $path = storage_path('app/tmp/test-bulk-'.$invoice->id.'.pdf');
                File::ensureDirectoryExists(dirname($path));
                File::put($path, '%PDF-1.4 test');

                return $path;
            });
        $this->app->instance(InvoicePdfService::class, $mock);

        $response = $this->post(route('invoicing.invoices.export-pdf-zip'), [
            'invoice_ids' => [$invoiceA->id, $invoiceB->id],
        ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $this->assertGreaterThan(100, strlen($response->streamedContent()));
    }

    public function test_export_pdf_zip_rejects_invoice_ids_from_other_team(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->actingTeamContext($user, $team);

        $otherTeam = Team::factory()->create();
        $otherClient = Client::factory()->for($otherTeam)->create();
        $foreignInvoice = Invoice::factory()->for($otherTeam)->for($otherClient)->create();

        $response = $this->postJson(route('invoicing.invoices.export-pdf-zip'), [
            'invoice_ids' => [$foreignInvoice->id],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonFragment(['message' => 'One or more invoices could not be found.']);
    }
}
