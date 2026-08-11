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
            ->post(route('wealth.assets.transactions.store', $asset), [
                'type' => 'contribution',
                'occurred_on' => '2026-05-10',
                'amount_cents' => 250_000,
                'notes' => 'Debit order',
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
                ->where('detail.current_value_cents', 12_500_000)
                ->has('detail.valuations', 1)
                ->has('detail.transactions', 1)
                ->has('transaction_types'));
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
