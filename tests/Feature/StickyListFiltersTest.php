<?php

namespace Tests\Feature;

use App\Domain\Invoicing\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StickyListFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_search_stays_applied_until_it_is_replaced(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->assertNotNull($team);

        Client::factory()->for($team)->create(['name' => 'Acme Studio']);
        Client::factory()->for($team)->create(['name' => 'Beta Works']);

        $this->actingAs($owner)
            ->get(route('invoicing.clients.index', ['search' => 'Acme']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.name', 'Acme Studio')
                ->where('filters.search', 'Acme'));

        $this->actingAs($owner)
            ->get(route('invoicing.clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('clients.data', 1)
                ->where('filters.search', 'Acme'));

        $this->actingAs($owner)
            ->get(route('invoicing.clients.index', [
                'status' => 'active',
                'page' => 1,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('clients.data', 2)
                ->where('filters.search', null));
    }
}
