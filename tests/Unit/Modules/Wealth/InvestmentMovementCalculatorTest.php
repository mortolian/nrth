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
        $asset = $this->makeAsset();

        $this->valuation($asset, '2026-02-28', 10_000_000);
        $this->transaction($asset, WealthTransactionType::Contribution, '2026-03-15', 1_000_000);
        $this->valuation($asset, '2026-03-31', 11_500_000);

        $result = (new InvestmentMovementCalculator)->forAsset(
            $asset->fresh(['portfolio']),
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-03-31'),
        );

        $this->assertSame(10_000_000, $result['opening_cents']);
        $this->assertSame(11_500_000, $result['closing_cents']);
        $this->assertSame(1_000_000, $result['contributions_cents']);
        $this->assertSame(0, $result['withdrawals_cents']);
        $this->assertSame(500_000, $result['investment_movement_cents']);
        $this->assertFalse($result['used_synthetic_opening']);
        $this->assertSame('2026-02-28', $result['opening_as_of']);
    }

    public function test_current_fy_with_prior_year_end_is_ytd_only(): void
    {
        $asset = $this->makeAsset();

        $this->valuation($asset, '2025-02-28', 5_000_000);
        $this->valuation($asset, '2026-02-28', 10_000_000);
        $this->valuation($asset, '2026-03-31', 10_200_000);
        $this->valuation($asset, '2026-07-31', 11_000_000);

        $result = (new InvestmentMovementCalculator)->forAsset(
            $asset->fresh(['portfolio']),
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-08-15'),
        );

        $this->assertSame(10_000_000, $result['opening_cents']);
        $this->assertSame(11_000_000, $result['closing_cents']);
        $this->assertSame(1_000_000, $result['investment_movement_cents']);
        $this->assertFalse($result['used_synthetic_opening']);
    }

    public function test_gap_before_current_fy_does_not_absorb_ancient_gains(): void
    {
        $asset = $this->makeAsset();

        // Last snapshot years before the current FY — must not become FY 2026/27 opening.
        $this->valuation($asset, '2021-02-28', 5_000_000);
        $this->valuation($asset, '2026-03-31', 10_200_000);
        $this->valuation($asset, '2026-07-31', 11_000_000);

        $result = (new InvestmentMovementCalculator)->forAsset(
            $asset->fresh(['portfolio']),
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-08-15'),
        );

        $this->assertSame(10_200_000, $result['opening_cents']);
        $this->assertSame(11_000_000, $result['closing_cents']);
        $this->assertSame(800_000, $result['investment_movement_cents']);
        $this->assertTrue($result['used_synthetic_opening']);
        $this->assertSame('2026-03-31', $result['opening_as_of']);
    }

    public function test_mid_year_start_uses_first_valuation_as_opening(): void
    {
        $asset = $this->makeAsset();

        $this->valuation($asset, '2026-05-01', 12_500_000);
        $this->transaction($asset, WealthTransactionType::Contribution, '2026-05-10', 250_000);
        $this->valuation($asset, '2026-06-01', 13_000_000);

        $result = (new InvestmentMovementCalculator)->forAsset(
            $asset->fresh(['portfolio']),
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-08-20'),
        );

        $this->assertSame(12_500_000, $result['opening_cents']);
        $this->assertSame(13_000_000, $result['closing_cents']);
        $this->assertSame(250_000, $result['contributions_cents']);
        $this->assertSame(250_000, $result['investment_movement_cents']);
        $this->assertTrue($result['used_synthetic_opening']);
    }

    public function test_contribution_before_first_valuation_is_excluded_when_synthetic_opening(): void
    {
        $asset = $this->makeAsset();

        // Contribution before any valuation — already reflected in the May snapshot.
        $this->transaction($asset, WealthTransactionType::Contribution, '2026-04-01', 500_000);
        $this->valuation($asset, '2026-05-01', 12_500_000);
        $this->valuation($asset, '2026-06-01', 13_000_000);

        $result = (new InvestmentMovementCalculator)->forAsset(
            $asset->fresh(['portfolio']),
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-08-20'),
        );

        $this->assertSame(12_500_000, $result['opening_cents']);
        $this->assertSame(0, $result['contributions_cents']);
        $this->assertSame(500_000, $result['investment_movement_cents']);
        $this->assertTrue($result['used_synthetic_opening']);
    }

    public function test_zero_prior_year_valuation_is_a_valid_opening(): void
    {
        $asset = $this->makeAsset();

        $this->valuation($asset, '2026-02-28', 0);
        $this->valuation($asset, '2026-06-01', 1_000_000);

        $result = (new InvestmentMovementCalculator)->forAsset(
            $asset->fresh(['portfolio']),
            Carbon::parse('2026-03-01'),
            Carbon::parse('2026-08-20'),
        );

        $this->assertSame(0, $result['opening_cents']);
        $this->assertSame(1_000_000, $result['closing_cents']);
        $this->assertSame(1_000_000, $result['investment_movement_cents']);
        $this->assertFalse($result['used_synthetic_opening']);
        $this->assertSame('2026-02-28', $result['opening_as_of']);
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

    public function test_trailing_year_allows_carry_forward_opening(): void
    {
        $asset = $this->makeAsset();

        // Ancient snapshot still opens the YoY window (unlike FY movement).
        $this->valuation($asset, '2024-08-01', 10_000_000);
        $this->transaction($asset, WealthTransactionType::Contribution, '2026-01-15', 500_000);
        $this->valuation($asset, '2026-08-01', 11_200_000);

        $result = (new InvestmentMovementCalculator)->forAssetsTrailingYear(
            collect([$asset->fresh(['portfolio'])]),
            Carbon::parse('2026-08-01'),
            'ZAR',
        );

        $this->assertSame('2025-08-01', $result['starts_on']);
        $this->assertSame('2026-08-01', $result['ends_on']);
        $this->assertSame(10_000_000, $result['opening_cents']);
        $this->assertSame(11_200_000, $result['closing_cents']);
        $this->assertSame(500_000, $result['contributions_cents']);
        // 11.2m − 10m − 0.5m = 0.7m growth
        $this->assertSame(700_000, $result['investment_movement_cents']);
        $this->assertSame(7.0, $result['change_percent']);
        $this->assertFalse($result['used_synthetic_opening']);
    }

    public function test_trailing_year_synthetic_open_when_no_prior_valuation(): void
    {
        $asset = $this->makeAsset();

        $this->valuation($asset, '2026-03-01', 8_000_000);
        $this->valuation($asset, '2026-08-01', 8_400_000);

        $result = (new InvestmentMovementCalculator)->forAssetsTrailingYear(
            collect([$asset->fresh(['portfolio'])]),
            Carbon::parse('2026-08-01'),
            'ZAR',
        );

        $this->assertTrue($result['used_synthetic_opening']);
        $this->assertSame(8_000_000, $result['opening_cents']);
        $this->assertSame(8_400_000, $result['closing_cents']);
        $this->assertSame(400_000, $result['investment_movement_cents']);
        $this->assertSame(5.0, $result['change_percent']);
    }

    private function makeAsset(): WealthAsset
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

        return WealthAsset::query()->create([
            'team_id' => $team->id,
            'portfolio_id' => $portfolio->id,
            'name' => 'Broker',
            'owner_name' => 'Alex',
            'asset_type' => WealthAssetType::InvestmentAccount,
            'currency' => 'ZAR',
            'liquidity' => WealthLiquidity::Accessible,
            'is_active' => true,
        ]);
    }

    private function valuation(WealthAsset $asset, string $date, int $cents): void
    {
        WealthAssetValuation::query()->create([
            'team_id' => $asset->team_id,
            'asset_id' => $asset->id,
            'valued_on' => $date,
            'value_cents' => $cents,
            'currency' => $asset->currency,
            'source' => 'manual',
        ]);
    }

    private function transaction(WealthAsset $asset, WealthTransactionType $type, string $date, int $cents): void
    {
        WealthAssetTransaction::query()->create([
            'team_id' => $asset->team_id,
            'asset_id' => $asset->id,
            'type' => $type,
            'occurred_on' => $date,
            'amount_cents' => $cents,
            'currency' => $asset->currency,
            'source' => 'manual',
        ]);
    }
}
