<?php

namespace Tests\Feature\Invoicing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_pair_returns_rate_one(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $response = $this->getJson(route('invoicing.exchange-rate', [
            'from' => 'ZAR',
            'to' => 'ZAR',
        ]));

        $response->assertOk();
        $response->assertJson([
            'rate' => 1.0,
            'source' => 'identity',
        ]);
    }

    public function test_returns_frankfurter_rate_when_available(): void
    {
        Http::fake([
            'https://api.frankfurter.dev/v2/rate/USD/ZAR*' => Http::response([
                'date' => '2026-04-28',
                'base' => 'USD',
                'quote' => 'ZAR',
                'rate' => 18.5,
            ], 200),
        ]);

        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $response = $this->getJson(route('invoicing.exchange-rate', [
            'from' => 'USD',
            'to' => 'ZAR',
        ]));

        $response->assertOk();
        $response->assertJsonPath('rate', 18.5);
        $response->assertJsonPath('date', '2026-04-28');
        $response->assertJsonPath('source', 'frankfurter');
    }

    public function test_accepts_historical_date_query(): void
    {
        Http::fake([
            'https://api.frankfurter.dev/v2/rate/EUR/USD?date=2024-01-02' => Http::response([
                'date' => '2024-01-02',
                'base' => 'EUR',
                'quote' => 'USD',
                'rate' => 1.1,
            ], 200),
        ]);

        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $response = $this->getJson(route('invoicing.exchange-rate', [
            'from' => 'EUR',
            'to' => 'USD',
            'date' => '2024-01-02',
        ]));

        $response->assertOk();
        $response->assertJsonPath('rate', 1.1);
        $response->assertJsonPath('date', '2024-01-02');
    }

    public function test_rejects_invalid_currency_code(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $response = $this->getJson(route('invoicing.exchange-rate', [
            'from' => 'XXX',
            'to' => 'ZAR',
        ]));

        $response->assertUnprocessable();
    }

    public function test_guest_cannot_fetch_rate(): void
    {
        $response = $this->getJson(route('invoicing.exchange-rate', [
            'from' => 'USD',
            'to' => 'ZAR',
        ]));

        $response->assertUnauthorized();
    }
}
