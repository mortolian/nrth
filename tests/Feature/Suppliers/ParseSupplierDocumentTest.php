<?php

namespace Tests\Feature\Suppliers;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParseSupplierDocumentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Team}
     */
    private function actingTeam(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        return [$user, $team];
    }

    /**
     * @param  array{provider?: string, api_key?: string, model?: string, base_url?: string}  $ai
     */
    private function configureAi(Team $team, array $ai = []): void
    {
        $team->forceFill([
            'business_settings' => array_replace_recursive(
                is_array($team->business_settings) ? $team->business_settings : [],
                [
                    'ai' => array_merge([
                        'enabled' => true,
                        'provider' => 'openai',
                        'api_key' => 'sk-test',
                        'model' => 'gpt-4o-mini',
                        'base_url' => null,
                    ], $ai),
                ]
            ),
        ])->save();
    }

    public function test_parse_document_requires_configured_api_key(): void
    {
        config([
            'services.openai.api_key' => null,
            'services.anthropic.api_key' => null,
        ]);
        $this->actingTeam();

        $this->postJson(route('suppliers.parse-document'), [
            'document' => UploadedFile::fake()->image('letterhead.jpg'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('document');
    }

    public function test_parse_document_requires_file(): void
    {
        [, $team] = $this->actingTeam();
        $this->configureAi($team);

        $this->postJson(route('suppliers.parse-document'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document');
    }

    public function test_parse_document_maps_openai_response(): void
    {
        [, $team] = $this->actingTeam();
        $this->configureAi($team);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'name' => 'Makro Sandton',
                            'contact_name' => 'Accounts',
                            'email' => 'accounts@makro.test',
                            'phone' => '+27115551234',
                            'vat_number' => '4 123456789',
                            'registration_number' => '1999/012345/07',
                            'address' => [
                                'street' => '1 Trade Route',
                                'city' => 'Sandton',
                                'province' => 'Gauteng',
                                'postal_code' => '2196',
                                'country' => null,
                            ],
                            'notes' => null,
                            'confidence' => 0.92,
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson(route('suppliers.parse-document'), [
            'document' => UploadedFile::fake()->image('invoice.jpg'),
        ])->assertOk()
            ->assertJsonPath('data.name', 'Makro Sandton')
            ->assertJsonPath('data.contact_name', 'Accounts')
            ->assertJsonPath('data.email', 'accounts@makro.test')
            ->assertJsonPath('data.phone', '+27115551234')
            ->assertJsonPath('data.vat_number', '4123456789')
            ->assertJsonPath('data.registration_number', '1999/012345/07')
            ->assertJsonPath('data.address.street', '1 Trade Route')
            ->assertJsonPath('data.address.city', 'Sandton')
            ->assertJsonPath('data.address.country', 'South Africa')
            ->assertJsonPath('data.confidence', 0.92);
    }

    public function test_parse_document_drops_invalid_vat_number(): void
    {
        [, $team] = $this->actingTeam();
        $this->configureAi($team);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'name' => 'Odd Vat Co',
                            'contact_name' => null,
                            'email' => null,
                            'phone' => null,
                            'vat_number' => 'VAT123',
                            'registration_number' => null,
                            'address' => null,
                            'notes' => null,
                            'confidence' => 0.5,
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson(route('suppliers.parse-document'), [
            'document' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertOk()
            ->assertJsonPath('data.name', 'Odd Vat Co')
            ->assertJsonPath('data.vat_number', null);
    }
}
