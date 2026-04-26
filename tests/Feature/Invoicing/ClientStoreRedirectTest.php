<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Models\Client;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientStoreRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_client_store_redirects_to_allowed_return_path(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $response = $this->post(route('invoicing.clients.store'), [
            'name' => 'Return Test Client',
            'contact_name' => null,
            'email' => null,
            'phone' => null,
            'vat_number' => null,
            'registration_number' => null,
            'address' => [
                'street' => null,
                'city' => null,
                'province' => null,
                'postal_code' => null,
                'country' => null,
            ],
            'currency' => 'ZAR',
            'payment_terms_days' => 30,
            'notes' => null,
            'is_active' => true,
            'return' => '/invoicing/invoices/create',
        ]);

        $response->assertRedirect('/invoicing/invoices/create');
    }

    public function test_client_store_ignores_unsafe_return_url(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $response = $this->post(route('invoicing.clients.store'), [
            'name' => 'Safe Redirect Client',
            'contact_name' => null,
            'email' => null,
            'phone' => null,
            'vat_number' => null,
            'registration_number' => null,
            'address' => [
                'street' => null,
                'city' => null,
                'province' => null,
                'postal_code' => null,
                'country' => null,
            ],
            'currency' => 'ZAR',
            'payment_terms_days' => 30,
            'notes' => null,
            'is_active' => true,
            'return' => 'https://evil.example/phish',
        ]);

        $created = Client::query()->where('name', 'Safe Redirect Client')->first();
        $this->assertNotNull($created);
        $response->assertRedirect(route('invoicing.clients.show', $created));
    }
}
