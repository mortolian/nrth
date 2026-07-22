<?php

namespace Tests\Feature\Expenses;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParseExpenseReceiptTest extends TestCase
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

        Account::factory()->for($team)->expense()->create(['code' => '7500', 'name' => 'General expense']);

        return [$user, $team];
    }

    /**
     * @param  array{provider?: string, api_key?: string, model?: string, base_url?: string}  $ai
     */
    private function configureAi(Team $team, array $ai = []): void
    {
        $team->forceFill([
            'company_settings' => array_replace_recursive(
                is_array($team->company_settings) ? $team->company_settings : [],
                [
                    'ai' => array_merge([
                        'provider' => 'openai',
                        'api_key' => 'sk-test',
                        'model' => 'gpt-4o-mini',
                        'base_url' => null,
                    ], $ai),
                ]
            ),
        ])->save();
    }

    public function test_parse_receipt_requires_configured_api_key(): void
    {
        config([
            'services.openai.api_key' => null,
            'services.anthropic.api_key' => null,
        ]);
        $this->actingTeam();

        $this->postJson(route('expenses.parse-receipt'), [
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('receipt');
    }

    public function test_parse_receipt_requires_file(): void
    {
        [, $team] = $this->actingTeam();
        $this->configureAi($team);

        $this->postJson(route('expenses.parse-receipt'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('receipt');
    }

    public function test_parse_receipt_maps_openai_response_and_matches_supplier(): void
    {
        [, $team] = $this->actingTeam();
        $this->configureAi($team, [
            'provider' => 'openai',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o',
        ]);
        $supplier = Supplier::factory()->for($team)->create(['name' => 'Makro Sandton', 'is_active' => true]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'date' => '2026-05-01',
                            'supplier_name' => 'Makro Sandton',
                            'description' => 'Office stationery',
                            'amount_excl_vat' => 100.0,
                            'vat_amount' => 15.0,
                            'vat_rate' => 'vat15',
                            'reference' => 'INV-42',
                            'confidence' => 0.91,
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson(route('expenses.parse-receipt'), [
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertOk()
            ->assertJsonPath('data.date', '2026-05-01')
            ->assertJsonPath('data.supplier_id', $supplier->id)
            ->assertJsonPath('data.supplier', '')
            ->assertJsonPath('data.description', 'Office stationery')
            ->assertJsonPath('data.amount_excl_vat', 100)
            ->assertJsonPath('data.vat_amount', 15)
            ->assertJsonPath('data.vat_rate', 'vat15')
            ->assertJsonPath('data.reference', 'INV-42')
            ->assertJsonPath('data.confidence', 0.91);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $request['model'] === 'gpt-4o'
                && $request->hasHeader('Authorization', 'Bearer sk-test');
        });
    }

    public function test_parse_receipt_uses_anthropic_provider(): void
    {
        [, $team] = $this->actingTeam();
        $this->configureAi($team, [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'model' => 'claude-haiku-4-5',
        ]);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'date' => '2026-06-10',
                        'supplier_name' => 'Woolworths',
                        'description' => 'Groceries',
                        'amount_excl_vat' => 80,
                        'vat_amount' => 12,
                        'vat_rate' => 'vat15',
                        'reference' => 'W123',
                        'confidence' => 0.88,
                    ]),
                ]],
            ], 200),
        ]);

        $this->postJson(route('expenses.parse-receipt'), [
            'receipt' => UploadedFile::fake()->image('woolies.jpg'),
        ])->assertOk()
            ->assertJsonPath('data.supplier', 'Woolworths')
            ->assertJsonPath('data.reference', 'W123');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request['model'] === 'claude-haiku-4-5'
                && $request->hasHeader('x-api-key', 'sk-ant-test');
        });
    }

    public function test_parse_receipt_uses_one_off_supplier_when_no_match(): void
    {
        [, $team] = $this->actingTeam();
        $this->configureAi($team);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'date' => '2026-06-02',
                            'supplier_name' => 'Unknown Cafe',
                            'description' => 'Coffee',
                            'amount_excl_vat' => 45.5,
                            'vat_amount' => 0,
                            'vat_rate' => 'no_vat',
                            'reference' => null,
                            'confidence' => 0.7,
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson(route('expenses.parse-receipt'), [
            'receipt' => UploadedFile::fake()->image('cafe.jpg'),
        ])->assertOk()
            ->assertJsonPath('data.supplier_id', 0)
            ->assertJsonPath('data.supplier', 'Unknown Cafe')
            ->assertJsonPath('data.amount_excl_vat', 45.5);
    }

    public function test_parse_receipt_uses_gemini_provider(): void
    {
        [, $team] = $this->actingTeam();
        $this->configureAi($team, [
            'provider' => 'gemini',
            'api_key' => 'AIza-test',
            'model' => 'gemini-2.5-flash',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'date' => '2026-08-01',
                                'supplier_name' => 'Checkers',
                                'description' => 'Groceries',
                                'amount_excl_vat' => 50,
                                'vat_amount' => 7.5,
                                'vat_rate' => 'vat15',
                                'reference' => 'C1',
                                'confidence' => 0.9,
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson(route('expenses.parse-receipt'), [
            'receipt' => UploadedFile::fake()->image('checkers.jpg'),
        ])->assertOk()
            ->assertJsonPath('data.supplier', 'Checkers');
    }

    public function test_parse_receipt_uses_openrouter_provider(): void
    {
        [, $team] = $this->actingTeam();
        $this->configureAi($team, [
            'provider' => 'openrouter',
            'api_key' => 'sk-or-test',
            'model' => 'openai/gpt-4o-mini',
            'base_url' => 'https://openrouter.ai/api/v1',
        ]);

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'date' => '2026-08-02',
                            'supplier_name' => 'Pick n Pay',
                            'description' => 'Snacks',
                            'amount_excl_vat' => 20,
                            'vat_amount' => 3,
                            'vat_rate' => 'vat15',
                            'reference' => null,
                            'confidence' => 0.8,
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson(route('expenses.parse-receipt'), [
            'receipt' => UploadedFile::fake()->image('pnp.jpg'),
        ])->assertOk()
            ->assertJsonPath('data.supplier', 'Pick n Pay');

        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-or-test'));
    }

    public function test_parse_receipt_falls_back_to_env_api_key(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.openai.api_key' => 'sk-env',
            'services.openai.model' => 'gpt-4o-mini',
        ]);

        $this->actingTeam();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'date' => '2026-07-01',
                            'supplier_name' => null,
                            'description' => 'Fuel',
                            'amount_excl_vat' => 200,
                            'vat_amount' => 30,
                            'vat_rate' => 'vat15',
                            'reference' => null,
                            'confidence' => 0.8,
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson(route('expenses.parse-receipt'), [
            'receipt' => UploadedFile::fake()->image('fuel.jpg'),
        ])->assertOk()
            ->assertJsonPath('data.description', 'Fuel');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-env'));
    }
}
