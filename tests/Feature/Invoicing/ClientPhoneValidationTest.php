<?php

namespace Tests\Feature\Invoicing;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPhoneValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_client_store_rejects_invalid_phone(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $response = $this->post(route('invoicing.clients.store'), [
            'name' => 'Phone Test Client',
            'contact_name' => null,
            'email' => null,
            'phone' => '+27 000',
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
        ]);

        $response->assertSessionHasErrors('phone');
    }

    public function test_client_store_normalizes_valid_phone_to_e164(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->actingTeamContext($user, $team);

        $response = $this->post(route('invoicing.clients.store'), [
            'name' => 'E164 Client',
            'contact_name' => null,
            'email' => null,
            'phone' => '+27 82 123 4567',
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
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('clients', [
            'team_id' => $team->id,
            'name' => 'E164 Client',
            'phone' => '+27821234567',
        ]);
    }
}
