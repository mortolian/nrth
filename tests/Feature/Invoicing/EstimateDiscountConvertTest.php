<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\EstimateStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Estimate;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tax\Models\TaxRate;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstimateDiscountConvertTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_store_and_convert_preserves_discounts(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $team = $owner->currentTeam;
        TaxRate::factory()->for($team)->create([
            'name' => 'Standard',
            'rate' => 0.15,
            'is_default' => true,
            'is_active' => true,
        ]);
        $taxRate = TaxRate::queryWithoutTeamScope()->where('team_id', $team->id)->firstOrFail();
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                [
                    'vat_registered' => true,
                    'default_tax_rate_id' => $taxRate->id,
                ],
            ),
        ])->save();
        $client = Client::factory()->for($team)->create();

        $this->actingAs($owner)
            ->post(route('invoicing.estimates.store'), [
                'client_id' => $client->id,
                'number' => 'EST-DISC-1',
                'issue_date' => '2026-07-01',
                'expiry_date' => '2026-07-31',
                'currency' => 'ZAR',
                'discount_type' => 'percent',
                'discount_percent' => 10,
                'line_items' => [
                    [
                        'description' => 'Service',
                        'quantity' => 1,
                        'unit_price_cents' => 10000,
                        'vat_rate' => 0.15,
                        'discount_type' => 'fixed',
                        'discount_cents' => 1000,
                    ],
                ],
            ])
            ->assertRedirect();

        $estimate = Estimate::queryWithoutTeamScope()->where('number', 'EST-DISC-1')->first();
        $this->assertNotNull($estimate);
        $this->assertSame(1900, (int) $estimate->getRawOriginal('discount_total_cents'));
        $estimate->update(['status' => EstimateStatus::Accepted]);

        $this->actingAs($owner)
            ->post(route('invoicing.estimates.convert', $estimate), [
                'invoice_due_date' => '2026-08-15',
                'invoice_notes' => $estimate->notes,
                'invoice_footer' => $estimate->terms,
            ])
            ->assertRedirect();

        $invoice = Invoice::queryWithoutTeamScope()
            ->where('reference', 'Converted from EST-DISC-1')
            ->first();
        $this->assertNotNull($invoice);
        $this->assertSame('percent', $invoice->discount_type);
        $this->assertSame(1900, (int) $invoice->getRawOriginal('discount_total_cents'));
        $this->assertSame(9315, (int) $invoice->getRawOriginal('total_cents'));
        $line = $invoice->lineItems()->first();
        $this->assertSame('fixed', $line->discount_type);
        $this->assertSame(1000, (int) $line->discount_cents);
    }
}
