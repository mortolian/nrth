<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptionalModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_travel_and_planning_are_disabled_by_default(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        foreach ([
            ModuleCatalog::TRAVEL => 'vehicles.trips.index',
            ModuleCatalog::PLANNING => 'budgeting.index',
        ] as $module => $routeName) {
            $this->assertFalse($team->moduleEnabled($module), $module);

            $this->actingAs($owner)
                ->get(route($routeName))
                ->assertForbidden();
        }
    }

    public function test_owner_can_enable_optional_modules_and_open_indexes(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $this->actingAs($owner)
            ->put(route('settings.features.update'), [
                'modules' => [
                    ['name' => ModuleCatalog::TRAVEL, 'enabled' => true],
                    ['name' => ModuleCatalog::PLANNING, 'enabled' => true],
                    ['name' => ModuleCatalog::WEALTH, 'enabled' => false],
                ],
            ])
            ->assertRedirect();

        $team = $team->fresh();
        $this->assertTrue($team->moduleEnabled(ModuleCatalog::TRAVEL));
        $this->assertTrue($team->moduleEnabled(ModuleCatalog::PLANNING));

        $this->actingAs($owner)->get(route('vehicles.trips.index'))->assertOk();
        $this->actingAs($owner)->get(route('budgeting.index'))->assertOk();
    }

    public function test_features_page_lists_travel_planning_and_wealth(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->actingAs($owner)
            ->get(route('settings.features'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Features')
                ->has('modules', 3)
                ->where('modules.0.name', ModuleCatalog::TRAVEL)
                ->where('modules.1.name', ModuleCatalog::PLANNING)
                ->where('modules.2.name', ModuleCatalog::WEALTH)
                ->where('modules.0.enabled', false)
                ->where('modules.1.enabled', false)
                ->where('modules.2.enabled', false)
                ->where('modules.0.experimental', false)
                ->where('modules.1.experimental', false)
                ->where('modules.2.experimental', false));
    }
}
