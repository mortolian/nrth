<?php

namespace Tests\Feature\Budgeting;

use App\Domain\Budgeting\Models\Budget;
use App\Domain\Budgeting\Models\BudgetCategory;
use App\Domain\Budgeting\Models\BudgetItem;
use App\Models\Team;
use App\Models\User;
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

        return [$user, $team];
    }

    /**
     * @return array<string, mixed>
     */
    private function samplePayload(bool $setActive = true): array
    {
        return [
            'name' => 'FY Plan',
            'period_type' => 'annual',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 'ZAR',
            'set_active' => $setActive,
            'categories' => [
                [
                    'name' => 'Ops',
                    'envelope_cents' => 120_000,
                    'account_id' => null,
                    'items' => [
                        [
                            'label' => 'Software',
                            'monthly_amount_cents' => 5_000,
                            'currency' => 'ZAR',
                            'fx_budget_per_line_major' => null,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_store_syncs_categories_items_and_budget_currency_minor(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->samplePayload(true))
            ->assertRedirect(route('budgeting.index'));

        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);
        $this->assertTrue($budget->is_active);

        $cat = BudgetCategory::query()->where('budget_id', $budget->id)->first();
        $this->assertNotNull($cat);
        $this->assertSame('Ops', $cat->name);
        $this->assertSame(120_000, (int) $cat->envelope_cents);

        $item = BudgetItem::query()->where('budget_category_id', $cat->id)->first();
        $this->assertNotNull($item);
        $this->assertSame(5_000, (int) $item->monthly_amount_cents);
        $this->assertSame(5_000, (int) $item->monthly_budget_currency_cents);
        $this->assertSame('ZAR', $item->currency);
    }

    public function test_store_converts_foreign_line_currency_with_fx(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), [
            'name' => 'FX',
            'period_type' => 'annual',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 'ZAR',
            'set_active' => false,
            'categories' => [
                [
                    'name' => 'Intl',
                    'envelope_cents' => 1_000_000,
                    'account_id' => null,
                    'items' => [
                        [
                            'label' => 'USD sub',
                            'monthly_amount_cents' => 10_000,
                            'currency' => 'USD',
                            'fx_budget_per_line_major' => '18.5',
                        ],
                    ],
                ],
            ],
        ])->assertRedirect(route('budgeting.index'));

        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);
        $item = BudgetItem::query()
            ->whereHas('category', fn ($q) => $q->where('budget_id', $budget->id))
            ->first();
        $this->assertNotNull($item);
        $this->assertSame(10_000, (int) $item->monthly_amount_cents);
        $this->assertSame(185_000, (int) $item->monthly_budget_currency_cents);
    }

    public function test_store_leaves_existing_active_budgets_active_when_adding_another(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->samplePayload(true))
            ->assertRedirect(route('budgeting.index'));

        $first = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->where('name', 'FY Plan')->first();
        $this->assertNotNull($first);
        $this->assertTrue($first->is_active);

        $secondPayload = $this->samplePayload(true);
        $secondPayload['name'] = 'Next year';
        $this->post(route('budgeting.store'), $secondPayload)
            ->assertRedirect(route('budgeting.index'));

        $first->refresh();
        $this->assertTrue($first->is_active, 'Existing active budget must stay active when a new budget is added.');

        $second = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->where('name', 'Next year')->first();
        $this->assertNotNull($second);
        $this->assertTrue($second->is_active);
    }

    public function test_update_set_active_true_does_not_deactivate_other_budgets(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->samplePayload(true));
        $first = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($first);

        $inactivePayload = $this->samplePayload(false);
        $inactivePayload['name'] = 'Draft budget';
        $this->post(route('budgeting.store'), $inactivePayload);

        $second = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->where('name', 'Draft budget')->first();
        $this->assertNotNull($second);
        $this->assertFalse($second->is_active);

        $this->put(route('budgeting.update', $second), [
            'name' => 'Draft budget',
            'period_type' => 'annual',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 'ZAR',
            'set_active' => true,
            'categories' => $inactivePayload['categories'],
        ])->assertRedirect(route('budgeting.index'));

        $first->refresh();
        $second->refresh();
        $this->assertTrue($first->is_active);
        $this->assertTrue($second->is_active);
    }

    public function test_index_shows_active_budget_when_marked_active(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->samplePayload(true));

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

        $this->post(route('budgeting.store'), $this->samplePayload(false));

        $this->get(route('budgeting.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Budgeting/Index')
                ->has('trashed_budgets')
                ->where('active_budget', null));
    }

    public function test_update_and_destroy(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->samplePayload(true));

        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);

        $this->put(route('budgeting.update', $budget), [
            'name' => 'Updated',
            'period_type' => 'annual',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'currency' => 'ZAR',
            'set_active' => false,
            'categories' => [
                [
                    'name' => 'Ops2',
                    'envelope_cents' => 60_000,
                    'account_id' => null,
                    'items' => [
                        [
                            'label' => 'Software',
                            'monthly_amount_cents' => 2_000,
                            'currency' => 'ZAR',
                            'fx_budget_per_line_major' => null,
                        ],
                    ],
                ],
            ],
        ])->assertRedirect(route('budgeting.index'));

        $budget->refresh();
        $this->assertSame('Updated', $budget->name);
        $this->assertFalse($budget->is_active);
        $this->assertSame('Ops2', $budget->categories()->first()?->name);

        $this->delete(route('budgeting.destroy', $budget))
            ->assertRedirect(route('budgeting.index'));

        $budgetId = (int) $budget->id;
        $this->assertNull(Budget::queryWithoutTeamScope()->find($budgetId));
        $this->assertTrue(Budget::queryWithoutTeamScope()->onlyTrashed()->whereKey($budgetId)->exists());

        $this->delete(route('budgeting.force-destroy', $budgetId))
            ->assertRedirect(route('budgeting.index'));

        $this->assertNull(Budget::queryWithoutTeamScope()->withTrashed()->find($budgetId));
    }

    public function test_restore_soft_deleted_budget(): void
    {
        [, $team] = $this->userAndTeam();

        $this->post(route('budgeting.store'), $this->samplePayload(false))->assertRedirect(route('budgeting.index'));

        $budget = Budget::queryWithoutTeamScope()->where('team_id', $team->id)->first();
        $this->assertNotNull($budget);
        $budgetId = (int) $budget->id;

        $this->delete(route('budgeting.destroy', $budget))->assertRedirect(route('budgeting.index'));

        $this->assertNull(Budget::queryWithoutTeamScope()->find($budgetId));

        $this->post(route('budgeting.restore', $budgetId))->assertRedirect(route('budgeting.index'));

        $restored = Budget::queryWithoutTeamScope()->find($budgetId);
        $this->assertNotNull($restored);
        $this->assertFalse($restored->trashed());
    }
}
