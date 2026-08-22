<?php

namespace Tests\Feature\Budgeting;

use App\Domain\Budgeting\Models\Budget;
use App\Domain\Budgeting\Models\BudgetCategory;
use App\Domain\Budgeting\Models\BudgetItem;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Team}
     */
    private function userAndTeam(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->enableTeamModules($team);

        return [$user, $team];
    }

    /**
     * @return array<string, mixed>
     */
    private function headerPayload(bool $setActive = true, string $name = 'FY Plan', bool $hasPeriod = true): array
    {
        return [
            'name' => $name,
            'has_period' => $hasPeriod,
            'period_type' => $hasPeriod ? 'annual' : null,
            'start_date' => $hasPeriod ? '2026-01-01' : null,
            'end_date' => $hasPeriod ? '2026-12-31' : null,
            'currency' => 'ZAR',
            'set_active' => $setActive,
        ];
    }

    public function test_store_creates_header_only_and_redirects_to_show(): void
    {
        [, $team] = $this->userAndTeam();

        $response = $this->post(route('budgeting.store'), $this->headerPayload(true));

        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);
        $this->assertTrue($budget->is_active);
        $this->assertSame(0, $budget->categories()->count());

        $response->assertRedirect(route('budgeting.show', $budget));
    }

    public function test_show_renders_budget_page(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(true));
        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);

        $this->get(route('budgeting.show', $budget))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Budgeting/Show')
                ->where('budget.name', 'FY Plan')
                ->where('budget.currency', 'ZAR')
                ->where('budget.has_period', true)
                ->where('budget.total_planned', 0)
                ->where('budget.total_monthly_planned', 0)
                ->where('budget.has_tracking', false)
                ->missing('budget.total_allocated')
                ->has('expense_accounts')
                ->has('can_import_structure'));
    }

    public function test_category_and_item_crud_with_same_currency(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(true));
        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);

        $this->post(route('budgeting.categories.store', $budget), [
            'name' => 'Ops',
            'account_id' => null,
        ])->assertRedirect();

        $cat = BudgetCategory::query()->where('budget_id', $budget->id)->first();
        $this->assertNotNull($cat);
        $this->assertSame('Ops', $cat->name);

        $this->post(route('budgeting.items.store', [$budget, $cat]), [
            'label' => 'Software',
            'cadence' => 'monthly',
            'notes' => null,
            'monthly_amount_cents' => 5_000,
            'currency' => 'ZAR',
            'fx_budget_per_line_major' => null,
        ])->assertRedirect();

        $item = BudgetItem::query()->where('budget_category_id', $cat->id)->first();
        $this->assertNotNull($item);
        $this->assertSame(5_000, (int) $item->monthly_amount_cents);
        $this->assertSame(5_000, (int) $item->monthly_budget_currency_cents);
        $this->assertSame('ZAR', $item->currency);
        $this->assertSame('monthly', $item->cadenceEnum()->value);

        $this->put(route('budgeting.categories.update', [$budget, $cat]), [
            'name' => 'Operations',
            'account_id' => null,
        ])->assertRedirect();

        $cat->refresh();
        $this->assertSame('Operations', $cat->name);

        $this->put(route('budgeting.items.update', [$budget, $cat, $item]), [
            'label' => 'SaaS',
            'cadence' => 'monthly',
            'notes' => 'Includes Slack seats',
            'monthly_amount_cents' => 6_000,
            'currency' => 'ZAR',
            'fx_budget_per_line_major' => null,
        ])->assertRedirect();

        $item->refresh();
        $this->assertSame('SaaS', $item->label);
        $this->assertSame(6_000, (int) $item->monthly_amount_cents);
        $this->assertSame('Includes Slack seats', $item->notes);

        $this->delete(route('budgeting.items.destroy', [$budget, $cat, $item]))->assertRedirect();
        $this->assertNull(BudgetItem::query()->find($item->id));

        $this->delete(route('budgeting.categories.destroy', [$budget, $cat]))->assertRedirect();
        $this->assertNull(BudgetCategory::query()->find($cat->id));
    }

    public function test_store_item_converts_foreign_line_currency_with_fx(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(false, 'FX'));
        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);

        $this->post(route('budgeting.categories.store', $budget), [
            'name' => 'Intl',
            'account_id' => null,
        ]);

        $cat = BudgetCategory::query()->where('budget_id', $budget->id)->first();
        $this->assertNotNull($cat);

        $this->post(route('budgeting.items.store', [$budget, $cat]), [
            'label' => 'USD sub',
            'cadence' => 'monthly',
            'notes' => null,
            'monthly_amount_cents' => 10_000,
            'currency' => 'USD',
            'fx_budget_per_line_major' => '18.5',
        ])->assertRedirect();

        $item = BudgetItem::query()->where('budget_category_id', $cat->id)->first();
        $this->assertNotNull($item);
        $this->assertSame(10_000, (int) $item->monthly_amount_cents);
        $this->assertSame(185_000, (int) $item->monthly_budget_currency_cents);
    }

    public function test_update_header_does_not_wipe_categories(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(true));
        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);

        $this->post(route('budgeting.categories.store', $budget), [
            'name' => 'Ops',
            'account_id' => null,
        ]);
        $cat = BudgetCategory::query()->where('budget_id', $budget->id)->first();
        $this->assertNotNull($cat);
        $catId = (int) $cat->id;

        $this->put(route('budgeting.update', $budget), [
            'name' => 'Updated',
            'has_period' => true,
            'period_type' => 'annual',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 'ZAR',
            'set_active' => false,
        ])->assertRedirect(route('budgeting.show', $budget));

        $budget->refresh();
        $this->assertSame('Updated', $budget->name);
        $this->assertFalse($budget->is_active);
        $this->assertNotNull(BudgetCategory::query()->find($catId));
        $this->assertSame('Ops', BudgetCategory::query()->find($catId)?->name);
    }

    public function test_store_leaves_existing_active_budgets_active_when_adding_another(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(true));
        $first = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->where('name', 'FY Plan')->first();
        $this->assertNotNull($first);
        $this->assertTrue($first->is_active);

        $this->post(route('budgeting.store'), $this->headerPayload(true, 'Next year'));
        $first->refresh();
        $this->assertTrue($first->is_active, 'Existing active budget must stay active when a new budget is added.');

        $second = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->where('name', 'Next year')->first();
        $this->assertNotNull($second);
        $this->assertTrue($second->is_active);
    }

    public function test_update_set_active_true_does_not_deactivate_other_budgets(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(true));
        $first = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($first);

        $this->post(route('budgeting.store'), $this->headerPayload(false, 'Draft budget'));
        $second = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->where('name', 'Draft budget')->first();
        $this->assertNotNull($second);
        $this->assertFalse($second->is_active);

        $this->put(route('budgeting.update', $second), [
            'name' => 'Draft budget',
            'has_period' => true,
            'period_type' => 'annual',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 'ZAR',
            'set_active' => true,
        ])->assertRedirect(route('budgeting.show', $second));

        $first->refresh();
        $second->refresh();
        $this->assertTrue($first->is_active);
        $this->assertTrue($second->is_active);
    }

    public function test_index_shows_active_budget_when_marked_active(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(true));

        $this->get(route('budgeting.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Budgeting/Index')
                ->has('active_budget')
                ->has('trashed_budgets')
                ->where('active_budget.name', 'FY Plan')
                ->where('active_budget.currency', 'ZAR'));
    }

    public function test_index_has_no_active_budget_payload_when_none_active(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(false));

        $this->get(route('budgeting.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Budgeting/Index')
                ->has('trashed_budgets')
                ->where('active_budget', null));
    }

    public function test_import_structure_from_previous_budget(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(false, 'Previous'));
        $previous = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->where('name', 'Previous')->first();
        $this->assertNotNull($previous);

        $this->post(route('budgeting.categories.store', $previous), [
            'name' => 'Ops',
            'account_id' => null,
        ]);
        $prevCat = BudgetCategory::query()->where('budget_id', $previous->id)->first();
        $this->assertNotNull($prevCat);
        $this->post(route('budgeting.items.store', [$previous, $prevCat]), [
            'label' => 'Rent',
            'cadence' => 'monthly',
            'notes' => 'Office lease',
            'monthly_amount_cents' => 10_000,
            'currency' => 'ZAR',
            'fx_budget_per_line_major' => null,
        ]);

        $this->post(route('budgeting.store'), [
            'name' => 'Current',
            'has_period' => true,
            'period_type' => 'annual',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'currency' => 'ZAR',
            'set_active' => false,
        ]);
        $current = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->where('name', 'Current')->first();
        $this->assertNotNull($current);
        $this->assertSame(0, $current->categories()->count());

        $this->post(route('budgeting.import-structure', $current))->assertRedirect();

        $current->load('categories.items');
        $this->assertCount(1, $current->categories);
        $this->assertSame('Ops', $current->categories->first()?->name);
        $this->assertCount(1, $current->categories->first()?->items ?? []);
        $imported = $current->categories->first()?->items->first();
        $this->assertNotNull($imported);
        $this->assertSame('Rent', $imported->label);
        $this->assertSame('Office lease', $imported->notes);
        $this->assertSame('monthly', $imported->cadenceEnum()->value);
    }

    public function test_once_per_period_item_counts_once_in_period_total(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(true));
        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);

        $this->post(route('budgeting.categories.store', $budget), [
            'name' => 'Software',
            'account_id' => null,
        ]);
        $cat = BudgetCategory::query()->where('budget_id', $budget->id)->first();
        $this->assertNotNull($cat);

        $this->post(route('budgeting.items.store', [$budget, $cat]), [
            'label' => 'Annual license',
            'cadence' => 'once_per_period',
            'notes' => 'Renews in March',
            'monthly_amount_cents' => 120_000,
            'currency' => 'ZAR',
            'fx_budget_per_line_major' => null,
        ])->assertRedirect();

        $item = BudgetItem::query()->where('budget_category_id', $cat->id)->first();
        $this->assertNotNull($item);
        $this->assertSame('once_per_period', $item->cadenceEnum()->value);
        $this->assertSame('Renews in March', $item->notes);
        $this->assertSame(120_000, $item->periodTotalBudgetCents(12));
        $this->assertSame(10_000, $item->monthlyEquivalentBudgetCents(12));

        $this->get(route('budgeting.show', $budget))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Budgeting/Show')
                ->where('budget.total_planned', 120_000)
                ->where('budget.has_tracking', false)
                ->where('budget.categories.0.items.0.cadence', 'once_per_period')
                ->where('budget.categories.0.items.0.period_total_budget_cents', 120_000)
                ->where('budget.categories.0.items.0.monthly_budget_currency_cents', 10_000)
                ->where('budget.categories.0.period_planned_cents', 120_000)
                ->where('budget.categories.0.items.0.notes', 'Renews in March')
                ->missing('budget.categories.0.envelope_cents'));
    }

    public function test_import_structure_rejected_when_categories_exist(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(false, 'Previous'));
        $this->post(route('budgeting.store'), [
            'name' => 'Current',
            'has_period' => true,
            'period_type' => 'annual',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'currency' => 'ZAR',
            'set_active' => false,
        ]);
        $current = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->where('name', 'Current')->first();
        $this->assertNotNull($current);

        $this->post(route('budgeting.categories.store', $current), [
            'name' => 'Existing',
            'account_id' => null,
        ]);

        $this->post(route('budgeting.import-structure', $current))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_destroy_and_restore(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(false))->assertRedirect();

        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);
        $budgetId = (int) $budget->id;

        $this->delete(route('budgeting.destroy', $budget))
            ->assertRedirect(route('budgeting.index'));

        $this->assertNull(Budget::queryWithoutTeamScope()->find($budgetId));
        $this->assertTrue(Budget::queryWithoutTeamScope()->onlyTrashed()->whereKey($budgetId)->exists());

        $this->post(route('budgeting.restore', $budgetId))->assertRedirect(route('budgeting.index'));

        $restored = Budget::queryWithoutTeamScope()->find($budgetId);
        $this->assertNotNull($restored);
        $this->assertFalse($restored->trashed());

        $this->delete(route('budgeting.destroy', $restored));
        $this->delete(route('budgeting.force-destroy', $budgetId))
            ->assertRedirect(route('budgeting.index'));

        $this->assertNull(Budget::queryWithoutTeamScope()->withTrashed()->find($budgetId));
    }

    public function test_annual_item_spreads_over_months(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(true));
        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);

        $this->post(route('budgeting.categories.store', $budget), [
            'name' => 'Licenses',
            'account_id' => null,
        ]);
        $cat = BudgetCategory::query()->where('budget_id', $budget->id)->first();
        $this->assertNotNull($cat);

        $this->post(route('budgeting.items.store', [$budget, $cat]), [
            'label' => 'Hosting',
            'cadence' => 'annually',
            'notes' => null,
            'monthly_amount_cents' => 120_000,
            'currency' => 'ZAR',
            'fx_budget_per_line_major' => null,
        ])->assertRedirect();

        $item = BudgetItem::query()->where('budget_category_id', $cat->id)->first();
        $this->assertNotNull($item);
        $this->assertSame('annually', $item->cadenceEnum()->value);
        $this->assertSame(10_000, $item->monthlyEquivalentBudgetCents(12));
        $this->assertSame(120_000, $item->periodTotalBudgetCents(12));
        $this->assertSame(30_000, $item->periodTotalBudgetCents(3));
        $this->assertSame(120_000, $item->annualizedBudgetCents(12));

        $this->get(route('budgeting.show', $budget))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Budgeting/Show')
                ->where('budget.categories.0.items.0.cadence', 'annually')
                ->where('budget.categories.0.items.0.monthly_budget_currency_cents', 10_000)
                ->where('budget.categories.0.items.0.period_total_budget_cents', 120_000));
    }

    public function test_viewer_can_show_but_not_manage_nested_writes(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        /** @var Team $team */
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);
        $this->enableTeamModules($team);

        $viewer = User::factory()->create();
        $team->users()->attach($viewer, ['role' => RolePresets::VIEWER]);
        $viewer->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($owner);
        $this->post(route('budgeting.store'), $this->headerPayload(true));
        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);

        $this->actingAs($viewer->fresh());
        $this->get(route('budgeting.show', $budget))->assertOk();
        $this->get(route('budgeting.create'))->assertForbidden();
        $this->post(route('budgeting.categories.store', $budget), [
            'name' => 'Nope',
            'account_id' => null,
        ])->assertForbidden();
    }

    public function test_store_ongoing_budget_without_period(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->headerPayload(true, 'Standing plan', false))
            ->assertRedirect();

        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);
        $this->assertNull($budget->period_type);
        $this->assertNull($budget->start_date);
        $this->assertNull($budget->end_date);
        $this->assertFalse($budget->hasPeriod());

        $this->get(route('budgeting.show', $budget))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Budgeting/Show')
                ->where('budget.has_period', false)
                ->where('budget.period', 'Ongoing Budget')
                ->where('budget.months_in_period', 1));

        $this->get(route('budgeting.edit', $budget))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Budgeting/Form')
                ->where('budget.has_period', false)
                ->where('budget.start_date', null));
    }
}
