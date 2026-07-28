<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLineItem;
use App\Domain\Invoicing\Models\Item;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_and_delete_item(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);

        $this->actingAs($owner)
            ->post(route('invoicing.items.store'), [
                'name' => 'Consulting hour',
                'description' => 'Hourly consulting',
                'unit' => 'hour',
                'unit_price_cents' => 150000,
                'default_vat_rate' => 0.15,
                'is_active' => true,
            ])
            ->assertRedirect();

        $item = Item::queryWithoutTeamScope()->where('name', 'Consulting hour')->first();
        $this->assertNotNull($item);

        $this->actingAs($owner)
            ->delete(route('invoicing.items.destroy', $item))
            ->assertRedirect(route('invoicing.items.index'));

        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }

    public function test_viewer_cannot_manage_items(): void
    {
        [$owner, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);

        $this->actingAs($viewer)
            ->get(route('invoicing.items.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('invoicing.items.store'), [
                'name' => 'Blocked',
                'unit_price_cents' => 100,
                'is_active' => true,
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('invoicing.items.index'))
            ->assertOk();
    }

    public function test_invoice_line_can_snapshot_item_id_and_delete_nulls_fk(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        EnsureTeamSystemRoles::ensureFor($owner->currentTeam);
        $teamId = (int) $owner->current_team_id;

        $client = Client::factory()->create(['team_id' => $teamId]);
        $item = Item::factory()->create([
            'team_id' => $teamId,
            'name' => 'Logo design',
            'description' => 'Brand logo package',
            'unit_price_cents' => 500000,
            'default_vat_rate' => 0.15,
        ]);

        $this->actingAs($owner)
            ->post(route('invoicing.invoices.store'), [
                'client_id' => $client->id,
                'number' => 'INV-TEST-ITEM-1',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'currency' => 'ZAR',
                'line_items' => [
                    [
                        'description' => 'Brand logo package',
                        'quantity' => 1,
                        'unit_price_cents' => 500000,
                        'vat_rate' => 0.15,
                        'item_id' => $item->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $invoice = Invoice::queryWithoutTeamScope()->where('number', 'INV-TEST-ITEM-1')->first();
        $this->assertNotNull($invoice);
        $line = InvoiceLineItem::query()->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($line);
        $this->assertSame($item->id, (int) $line->item_id);
        $this->assertSame('Brand logo package', $line->description);

        $this->actingAs($owner)
            ->delete(route('invoicing.items.destroy', $item))
            ->assertRedirect(route('invoicing.items.index'));

        $line->refresh();
        $this->assertNull($line->item_id);
        $this->assertSame('Brand logo package', $line->description);
        $this->assertSame(500000, (int) $line->unit_price_cents);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function ownerAndMember(string $role): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => $role]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        return [$owner, $member];
    }
}
