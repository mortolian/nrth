<?php

namespace Tests\Feature\Tax;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Takeout\Jobs\GenerateTakeoutJob;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class TakeoutVoidedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_voided_invoice_appears_in_register(): void
    {
        Storage::fake('local');

        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $client = Client::factory()->for($team)->create();

        Invoice::factory()->for($team)->for($client)->create([
            'number' => 'INV-VOID-001',
            'status' => InvoiceStatus::Void,
            'issue_date' => '2026-06-10',
            'voided_at' => now(),
        ]);

        $run = TakeoutRun::factory()->for($team)->create([
            'requested_by' => $user->id,
            'from_date' => '2026-06-01',
            'to_date' => '2026-06-30',
        ]);

        (new GenerateTakeoutJob($run->id))->handle(app(\App\Domain\Takeout\Services\TakeoutBuilder::class));

        $zipPath = storage_path('app/private/'.$run->fresh()->storage_path);
        $zip = new ZipArchive;
        $zip->open($zipPath);

        $csvName = 'nrth-takeout_2026-06-01_to_2026-06-30/figures/invoices-register.csv';
        $csv = $zip->getFromName($csvName);
        $zip->close();

        $this->assertIsString($csv);
        $this->assertStringContainsString('INV-VOID-001', $csv);
        $this->assertStringContainsString('void', strtolower($csv));
    }
}
