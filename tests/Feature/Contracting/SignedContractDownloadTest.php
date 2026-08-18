<?php

namespace Tests\Feature\Contracting;

use App\Domain\Contracting\Models\Contract;
use App\Domain\Invoicing\Models\Client;
use App\Models\User;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SignedContractDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_contract_is_served_through_an_authorized_route(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);
        $team->setModuleEnabled(ModuleCatalog::CONTRACTING, true);

        $client = Client::factory()->for($team)->create();
        $contract = Contract::queryWithoutTeamScope()->create([
            'team_id' => $team->id,
            'client_id' => $client->id,
            'title' => 'MSA',
            'status' => 'active',
            'billing_type' => 'fixed',
            'start_date' => '2026-01-01',
            'contract_value_cents' => 100_00,
        ]);

        $tmp = storage_path('app/private/tmp-msa-test.pdf');
        File::ensureDirectoryExists(dirname($tmp));
        File::put($tmp, '%PDF-1.4 test-contract');
        $contract->addMedia($tmp)->usingFileName('msa.pdf')->toMediaCollection('signed-contract');
        File::delete($tmp);

        $downloadUrl = route('contracting.contracts.signed-document', $contract);

        $this->actingAs($owner)
            ->get(route('contracting.contracts.edit', $contract))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contract.signed_contract_url', $downloadUrl));

        $this->actingAs($owner)
            ->get($downloadUrl)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');

        $stranger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($stranger)
            ->get($downloadUrl)
            ->assertNotFound();
    }
}
