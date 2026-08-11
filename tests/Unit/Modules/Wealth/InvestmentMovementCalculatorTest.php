<?php

namespace Tests\Unit\Modules\Wealth;

use App\Models\User;
use App\Modules\Wealth\Calculators\InvestmentMovementCalculator;
use App\Modules\Wealth\Enums\WealthAssetType;
use App\Modules\Wealth\Enums\WealthLiquidity;
use App\Modules\Wealth\Enums\WealthTransactionType;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthAssetTransaction;
use App\Modules\Wealth\Models\WealthAssetValuation;
use App\Modules\Wealth\Models\WealthPortfolio;
use App\Modules\Wealth\Services\WealthFinancialYear;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentMovementCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_investment_movement_with_prior_opening_valuation(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $this->actingAs($owner);

        $portfolio = WealthPortfolio::query()->create([
            'team_id' => $team->id,
            'name' => 'Household',
            'base_currency' => 'ZAR',
            'financial_year_start_month' => 3,
            'is_default' => true,
        ]);

        $asset = WealthAsset::query()->create([
            'team_id' => $team->id,
            'portfolio_id' => $portfolio->id,
            'name' => 'Broker',
            'owner_name' => 'Alex',
            'asset_type' => WealthAssetType::InvestmentAccount,
            'currency' => 'ZAR',
            'liquidity' => WealthLiquidity::Accessible,
            'is_active' => true,
        ]);

        WealthAssetValuation::query()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'valued_on' => '2026-02-28',
            'value_cents' => 10_000_000,
            'currency' => 'ZAR',
            'source' => 'manual',
        ]);

        WealthAssetTransaction::query()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'type' => WealthTransactionType::Contribution,
            'occurred_on' => '2026-03-15',
            'amount_cents' => 1_000_000,
            'currency' => 'ZAR',
            'source' => 'manual',
        ]);

        WealthAssetValuation::query()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'valued_on' => '2026-03-31',
            'value_cents' => 11_500_000,
            'currency' => 'ZAR',
            'source' => 'manual',
        ]);

        $result = (new InvestmentMovementCalculator)->forAsset(
            $asset->fresh(),
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
        );

        $this->assertSame(10_000_000, $result['opening_cents']);
        $this->assertSame(11_500_000, $result['closing_cents']);
        $this->assertSame(1_000_000, $result['contributions_cents']);
        $this->assertSame(0, $result['withdrawals_cents']);
        $this->assertSame(500_000, $result['investment_movement_cents']);
    }

    public function test_financial_year_march_start(): void
    {
        [$start, $end] = WealthFinancialYear::windowContaining(Carbon::parse('2026-04-15'), 3);

        $this->assertSame('2026-03-01', $start->toDateString());
        $this->assertSame('2027-02-28', $end->toDateString());

        [$start2, $end2] = WealthFinancialYear::windowContaining(Carbon::parse('2026-02-10'), 3);
        $this->assertSame('2025-03-01', $start2->toDateString());
        $this->assertSame('2026-02-28', $end2->toDateString());
    }
}
