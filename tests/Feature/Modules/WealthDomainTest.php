<?php

namespace Tests\Feature\Modules;

use App\Models\Team;
use App\Models\User;
use App\Modules\Wealth\Enums\WealthAssetType;
use App\Modules\Wealth\Enums\WealthLiquidity;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthPortfolio;
use App\Support\Modules\ModuleCatalog;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WealthDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_asset_and_valuation_when_module_enabled(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->setModuleEnabled(ModuleCatalog::WEALTH, true);

        $this->actingAs($owner)
            ->post(route('wealth.assets.store'), [
                'name' => 'TFSA',
                'owner_name' => 'Alex',
                'asset_type' => WealthAssetType::TaxFreeSavings->value,
                'institution' => 'EasyEquities',
                'liquidity' => WealthLiquidity::Accessible->value,
                'opening_value_cents' => 4_600_000,
                'opening_valued_on' => '2026-03-01',
            ])
            ->assertRedirect();

        $asset = WealthAsset::query()->where('name', 'TFSA')->first();
        $this->assertNotNull($asset);
        $this->assertSame(4_600_000, $asset->currentValueCents());

        $this->actingAs($owner)
            ->post(route('wealth.assets.valuations.store', $asset), [
                'valued_on' => '2026-04-01',
                'value_cents' => 4_800_000,
            ])
            ->assertRedirect();

        $this->assertSame(4_800_000, $asset->fresh()->currentValueCents());

        $this->actingAs($owner)
            ->get(route('wealth.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Wealth/Index')
                ->where('overview.total_cents', 4_800_000));
    }

    public function test_disabled_module_still_retains_data_rows(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->setModuleEnabled(ModuleCatalog::WEALTH, true);

        $portfolio = WealthPortfolio::query()->create([
            'team_id' => $team->id,
            'name' => 'Household',
            'base_currency' => 'ZAR',
            'financial_year_start_month' => 3,
            'is_default' => true,
        ]);

        WealthAsset::query()->create([
            'team_id' => $team->id,
            'portfolio_id' => $portfolio->id,
            'name' => 'Cash',
            'owner_name' => 'Alex',
            'asset_type' => WealthAssetType::Cash,
            'currency' => 'ZAR',
            'liquidity' => WealthLiquidity::ImmediatelyAvailable,
            'is_active' => true,
        ]);

        $team->setModuleEnabled(ModuleCatalog::WEALTH, false);

        $this->actingAs($owner)
            ->get(route('wealth.index'))
            ->assertForbidden();

        $this->assertSame(1, WealthAsset::queryWithoutTeamScope()->where('team_id', $team->id)->count());
    }

    public function test_viewer_cannot_create_asset(): void
    {
        [$owner, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);
        $owner->currentTeam->setModuleEnabled(ModuleCatalog::WEALTH, true);

        $this->actingAs($viewer)
            ->post(route('wealth.assets.store'), [
                'name' => 'Blocked',
                'owner_name' => 'Alex',
                'asset_type' => WealthAssetType::Cash->value,
                'liquidity' => WealthLiquidity::Accessible->value,
            ])
            ->assertForbidden();
    }

    public function test_owner_can_add_valuation_and_transaction_on_asset(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20'));

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->setModuleEnabled(ModuleCatalog::WEALTH, true);

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

        $this->actingAs($owner)
            ->post(route('wealth.assets.valuations.store', $asset), [
                'valued_on' => '2026-05-01',
                'value_cents' => 12_500_000,
                'notes' => 'Month end',
            ])
            ->assertRedirect();

        $this->assertTrue(
            $asset->valuations()->whereDate('valued_on', '2026-05-01')->where('value_cents', 12_500_000)->exists()
        );
        $this->assertSame(12_500_000, $asset->fresh()->currentValueCents());

        $this->actingAs($owner)
            ->post(route('wealth.assets.valuations.store', $asset), [
                'valued_on' => '2026-06-01',
                'value_cents' => 13_000_000,
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->post(route('wealth.assets.transactions.store', $asset), [
                'type' => 'contribution',
                'occurred_on' => '2026-05-10',
                'amount_cents' => 250_000,
                'notes' => 'Debit order',
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->post(route('wealth.assets.transactions.store', $asset), [
                'type' => 'withdrawal',
                'occurred_on' => '2026-05-20',
                'amount_cents' => 50_000,
            ])
            ->assertRedirect();

        $this->assertTrue(
            $asset->transactions()
                ->where('type', 'contribution')
                ->whereDate('occurred_on', '2026-05-10')
                ->where('amount_cents', 250_000)
                ->exists()
        );

        $this->actingAs($owner)
            ->get(route('wealth.assets.show', $asset))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Wealth/Assets/Show')
                ->where('detail.current_value_cents', 13_000_000)
                ->has('detail.valuations', 2)
                ->has('detail.transactions', 2)
                ->where('detail.valuations.0.valued_on', '2026-06-01')
                ->where('detail.valuations.0.value_cents', 13_000_000)
                ->where('detail.valuations.0.change_cents', 500_000)
                ->where('detail.valuations.0.change_percent', 4)
                ->where('detail.valuations.0.year_label', '2026/27')
                ->where('detail.valuations.1.change_cents', null)
                ->where('detail.transactions.0.signed_amount_cents', -50_000)
                ->where('detail.transactions.0.year_label', '2026/27')
                ->where('detail.transactions.1.signed_amount_cents', 250_000)
                ->where('detail.chart.1.change_cents', 500_000)
                ->has('detail.yearly_summaries', 1)
                ->where('detail.yearly_summaries.0.label', '2026/27')
                ->where('detail.yearly_summaries.0.is_current', true)
                ->where('detail.yearly_summaries.0.opening_cents', 12_500_000)
                ->where('detail.yearly_summaries.0.closing_cents', 13_000_000)
                ->where('detail.yearly_summaries.0.contributions_cents', 250_000)
                ->where('detail.yearly_summaries.0.withdrawals_cents', 50_000)
                ->where('detail.yearly_summaries.0.investment_movement_cents', 300_000)
                ->where('detail.financial_year.label', '2026/27')
                ->where('detail.financial_year.opening_cents', 12_500_000)
                ->where('detail.financial_year.contributions_cents', 250_000)
                ->where('detail.financial_year.withdrawals_cents', 50_000)
                ->where('detail.financial_year.investment_movement_cents', 300_000)
                ->has('transaction_types'));

        Carbon::setTestNow();
    }

    public function test_asset_show_groups_yearly_summaries_by_financial_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-04-15'));

        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->setModuleEnabled(ModuleCatalog::WEALTH, true);

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

        $this->actingAs($owner)
            ->post(route('wealth.assets.valuations.store', $asset), [
                'valued_on' => '2025-02-28',
                'value_cents' => 10_000_000,
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->post(route('wealth.assets.transactions.store', $asset), [
                'type' => 'contribution',
                'occurred_on' => '2025-06-01',
                'amount_cents' => 1_000_000,
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->post(route('wealth.assets.valuations.store', $asset), [
                'valued_on' => '2026-02-28',
                'value_cents' => 11_500_000,
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->post(route('wealth.assets.transactions.store', $asset), [
                'type' => 'contribution',
                'occurred_on' => '2026-05-01',
                'amount_cents' => 500_000,
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->post(route('wealth.assets.valuations.store', $asset), [
                'valued_on' => '2027-03-31',
                'value_cents' => 12_200_000,
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->get(route('wealth.assets.show', $asset))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Wealth/Assets/Show')
                ->has('detail.valuations', 3)
                ->where('detail.valuations.0.valued_on', '2027-03-31')
                ->where('detail.valuations.0.change_cents', null)
                ->where('detail.valuations.1.valued_on', '2026-02-28')
                ->where('detail.valuations.1.change_cents', null)
                ->where('detail.valuations.2.valued_on', '2025-02-28')
                ->where('detail.valuations.2.change_cents', null)
                ->where('detail.chart.1.change_cents', 1_500_000)
                ->has('detail.yearly_summaries', 4)
                ->where('detail.yearly_summaries.0.label', '2027/28')
                ->where('detail.yearly_summaries.0.is_current', true)
                ->where('detail.yearly_summaries.0.opening_cents', 12_200_000)
                ->where('detail.yearly_summaries.0.closing_cents', 12_200_000)
                ->where('detail.yearly_summaries.0.contributions_cents', 0)
                ->where('detail.yearly_summaries.0.investment_movement_cents', 0)
                ->where('detail.yearly_summaries.0.used_synthetic_opening', true)
                ->where('detail.yearly_summaries.1.label', '2026/27')
                ->where('detail.yearly_summaries.1.is_current', false)
                ->where('detail.yearly_summaries.1.opening_cents', 11_500_000)
                ->where('detail.yearly_summaries.1.closing_cents', 11_500_000)
                ->where('detail.yearly_summaries.1.contributions_cents', 500_000)
                ->where('detail.yearly_summaries.1.investment_movement_cents', -500_000)
                ->where('detail.yearly_summaries.1.used_synthetic_opening', false)
                ->where('detail.yearly_summaries.2.label', '2025/26')
                ->where('detail.yearly_summaries.2.opening_cents', 10_000_000)
                ->where('detail.yearly_summaries.2.closing_cents', 11_500_000)
                ->where('detail.yearly_summaries.2.contributions_cents', 1_000_000)
                ->where('detail.yearly_summaries.2.investment_movement_cents', 500_000)
                ->where('detail.yearly_summaries.3.label', '2024/25')
                ->where('detail.yearly_summaries.3.opening_cents', 10_000_000)
                ->where('detail.yearly_summaries.3.closing_cents', 10_000_000)
                ->where('detail.yearly_summaries.3.investment_movement_cents', 0)
                ->where('detail.yearly_summaries.3.used_synthetic_opening', true));

        Carbon::setTestNow();
    }

    public function test_owner_can_update_valuation_and_transaction_on_asset(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->setModuleEnabled(ModuleCatalog::WEALTH, true);

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

        $this->actingAs($owner)
            ->post(route('wealth.assets.valuations.store', $asset), [
                'valued_on' => '2026-05-01',
                'value_cents' => 12_500_000,
                'notes' => 'Month end',
            ])
            ->assertRedirect();

        $valuation = $asset->valuations()->first();
        $this->assertNotNull($valuation);

        $this->actingAs($owner)
            ->put(route('wealth.assets.valuations.update', [$asset, $valuation]), [
                'valued_on' => '2026-05-02',
                'value_cents' => 13_000_000,
                'notes' => 'Corrected',
            ])
            ->assertRedirect();

        $valuation->refresh();
        $this->assertSame('2026-05-02', $valuation->valued_on->toDateString());
        $this->assertSame(13_000_000, $valuation->value_cents);
        $this->assertSame('Corrected', $valuation->notes);
        $this->assertSame(13_000_000, $asset->fresh()->currentValueCents());

        $this->actingAs($owner)
            ->post(route('wealth.assets.transactions.store', $asset), [
                'type' => 'contribution',
                'occurred_on' => '2026-05-10',
                'amount_cents' => 250_000,
                'notes' => 'Debit order',
            ])
            ->assertRedirect();

        $transaction = $asset->transactions()->first();
        $this->assertNotNull($transaction);

        $this->actingAs($owner)
            ->put(route('wealth.assets.transactions.update', [$asset, $transaction]), [
                'type' => 'withdrawal',
                'occurred_on' => '2026-05-12',
                'amount_cents' => 100_000,
                'notes' => 'Cash out',
            ])
            ->assertRedirect();

        $transaction->refresh();
        $this->assertSame('withdrawal', $transaction->type->value);
        $this->assertSame('2026-05-12', $transaction->occurred_on->toDateString());
        $this->assertSame(100_000, $transaction->amount_cents);
        $this->assertSame('Cash out', $transaction->notes);
    }

    public function test_viewer_cannot_update_valuation_or_transaction(): void
    {
        [$owner, $viewer] = $this->ownerAndMember(RolePresets::VIEWER);
        $owner->currentTeam->setModuleEnabled(ModuleCatalog::WEALTH, true);

        $portfolio = WealthPortfolio::query()->create([
            'team_id' => $owner->currentTeam->id,
            'name' => 'Household',
            'base_currency' => 'ZAR',
            'financial_year_start_month' => 3,
            'is_default' => true,
        ]);

        $asset = WealthAsset::query()->create([
            'team_id' => $owner->currentTeam->id,
            'portfolio_id' => $portfolio->id,
            'name' => 'Broker',
            'owner_name' => 'Alex',
            'asset_type' => WealthAssetType::InvestmentAccount,
            'currency' => 'ZAR',
            'liquidity' => WealthLiquidity::Accessible,
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('wealth.assets.valuations.store', $asset), [
                'valued_on' => '2026-05-01',
                'value_cents' => 12_500_000,
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->post(route('wealth.assets.transactions.store', $asset), [
                'type' => 'contribution',
                'occurred_on' => '2026-05-10',
                'amount_cents' => 250_000,
            ])
            ->assertRedirect();

        $valuation = $asset->valuations()->first();
        $transaction = $asset->transactions()->first();
        $this->assertNotNull($valuation);
        $this->assertNotNull($transaction);

        $this->actingAs($viewer)
            ->put(route('wealth.assets.valuations.update', [$asset, $valuation]), [
                'valued_on' => '2026-05-02',
                'value_cents' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->put(route('wealth.assets.transactions.update', [$asset, $transaction]), [
                'type' => 'withdrawal',
                'occurred_on' => '2026-05-12',
                'amount_cents' => 1,
            ])
            ->assertForbidden();
    }

    public function test_owner_can_create_and_switch_portfolios(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $team->setModuleEnabled(ModuleCatalog::WEALTH, true);

        $this->actingAs($owner)
            ->get(route('wealth.index'))
            ->assertOk();

        $default = WealthPortfolio::query()->where('team_id', $team->id)->first();
        $this->assertNotNull($default);

        $this->actingAs($owner)
            ->post(route('wealth.portfolios.store'), [
                'name' => 'Business',
                'financial_year_start_month' => 3,
            ])
            ->assertRedirect();

        $business = WealthPortfolio::query()->where('team_id', $team->id)->where('name', 'Business')->first();
        $this->assertNotNull($business);

        WealthAsset::query()->create([
            'team_id' => $team->id,
            'portfolio_id' => $business->id,
            'name' => 'Biz cash',
            'owner_name' => 'Alex',
            'asset_type' => WealthAssetType::Cash,
            'currency' => 'ZAR',
            'liquidity' => WealthLiquidity::ImmediatelyAvailable,
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('wealth.index', ['portfolio' => $business->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Wealth/Index')
                ->where('portfolio.id', $business->id)
                ->where('portfolio.name', 'Business')
                ->has('portfolios', 2)
                ->has('overview.assets', 1));

        $this->actingAs($owner)
            ->get(route('wealth.index', ['portfolio' => $default->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('portfolio.id', $default->id)
                ->has('overview.assets', 0));
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function ownerAndMember(string $role): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        /** @var Team $team */
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => $role]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        return [$owner, $member->fresh()];
    }
}
