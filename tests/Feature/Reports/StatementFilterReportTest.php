<?php

namespace Tests\Feature\Reports;

use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingAccount;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Reports\Models\StatementFilterReport;
use App\Domain\Reports\Services\ReportPdf;
use App\Domain\Reports\Services\StatementFilterReportService;
use App\Models\TeamRole;
use App\Models\User;
use App\Support\TeamAccess\EnsureTeamSystemRoles;
use App\Support\TeamAccess\RolePresets;
use App\Support\TeamAccess\TeamAccess;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StatementFilterReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_filters_total_matching_statement_lines_and_can_export(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $account = BankingAccount::factory()->for($team)->create(['name' => 'Cheque']);

        $this->line($team->id, $account->id, '2026-03-04', 'WOOLWORTHS FOOD', null, '400.00', TransactionDirection::Debit);
        $this->line($team->id, $account->id, '2026-03-18', 'Card purchase', 'Woolworths', '50.00', TransactionDirection::Credit);
        $this->line($team->id, $account->id, '2026-08-02', 'Woolworths Canal Walk', null, '125.50', TransactionDirection::Debit);
        $this->line($team->id, $account->id, '2026-03-04', 'Shell fuel', null, '800.00', TransactionDirection::Debit);

        $other = User::factory()->withPersonalTeam()->create();
        $otherAccount = BankingAccount::factory()->for($other->currentTeam)->create();
        $this->line($other->currentTeam->id, $otherAccount->id, '2026-03-04', 'Woolworths other books', null, '999.00', TransactionDirection::Debit);

        $this->actingAs($user)
            ->post(route('reports.statement-filters.store'), [
                'name' => 'Household',
                'rules' => [
                    ['alias' => 'Woolies', 'match' => 'woolworths'],
                    ['alias' => 'Fuel', 'match' => 'Shell'],
                ],
            ])
            ->assertRedirect();

        $report = StatementFilterReport::query()->where('name', 'Household')->first();
        $this->assertNotNull($report);

        $this->actingAs($user)
            ->get(route('reports.statement-filters.show', [
                'statementFilterReport' => $report,
                'from' => '2026-03-01',
                'to' => '2026-08-31',
                'breakdown' => 'monthly',
                'view' => 'detailed',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/StatementFilters/Show')
                ->where('result.filters.0.alias', 'Woolies')
                ->where('result.filters.0.match', 'woolworths')
                ->where('result.filters.0.count', 3)
                ->where('result.filters.0.total_cents', 47550)
                ->where('result.filters.0.months.0.label', 'March 2026')
                ->where('result.filters.0.months.0.total_cents', 35000)
                ->where('result.filters.0.months.1.label', 'August 2026')
                ->where('result.filters.0.transactions.0.description', 'WOOLWORTHS FOOD')
                ->where('result.filters.1.alias', 'Fuel')
                ->where('result.filters.1.total_cents', 80000)
                ->where('result.filters.1.count', 1));
    }

    public function test_pdf_contains_the_alias_filter_and_total(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $account = BankingAccount::factory()->for($team)->create();
        $this->line($team->id, $account->id, '2026-03-04', 'Woolworths', null, '10.00', TransactionDirection::Debit);

        $report = StatementFilterReport::query()->create([
            'team_id' => $team->id,
            'name' => 'Groceries',
        ]);
        $report->replaceRules([
            ['alias' => 'Woolies', 'match' => 'Woolworths'],
        ]);

        $html = view('pdf.statement-filter-report', [
            ...ReportPdf::chrome($team->fresh()),
            'currency' => 'ZAR',
            'reportName' => 'Groceries',
            ...app(StatementFilterReportService::class)->run(
                $report->fresh(),
                Carbon::parse('2026-03-01'),
                Carbon::parse('2026-03-31'),
                'period',
                'detailed',
            ),
        ])->render();

        $this->assertStringContainsString('Woolies', $html);
        $this->assertStringContainsString('Woolworths', $html);
        $this->assertStringContainsString('10.00', $html);

        $this->actingAs($user)
            ->get(route('reports.statement-filters.pdf', [
                'statementFilterReport' => $report,
                'from' => '2026-03-01',
                'to' => '2026-03-31',
                'view' => 'detailed',
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_viewer_can_open_a_report_but_cannot_save_or_export(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $this->assertTrue(in_array('reports.manage', RolePresets::accountantPermissions(), true));
        $this->assertFalse(in_array('reports.manage', RolePresets::viewerPermissions(), true));

        $report = StatementFilterReport::query()->create([
            'team_id' => $team->id,
            'name' => 'Groceries',
        ]);
        $report->replaceRules([
            ['alias' => 'Woolies', 'match' => 'Woolworths'],
        ]);

        $role = TeamRole::query()->create([
            'team_id' => $team->id,
            'key' => 'reporter',
            'name' => 'Reporter',
            'description' => 'Reports only',
            'permissions' => ['reports.view'],
            'is_system' => false,
        ]);
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => $role->key]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->assertFalse(TeamAccess::allows($member->fresh(), $team->fresh(), 'reports.manage'));
        $this->assertFalse(TeamAccess::allows($member->fresh(), $team->fresh(), 'reports.export'));

        $this->actingAs($member)
            ->get(route('reports.statement-filters.show', $report))
            ->assertOk();

        $this->actingAs($member)
            ->post(route('reports.statement-filters.store'), [
                'name' => 'Nope',
                'rules' => [['alias' => 'X', 'match' => 'Y']],
            ])
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route('reports.statement-filters.pdf', $report))
            ->assertForbidden();
    }

    public function test_a_period_longer_than_five_years_returns_to_the_report_with_an_error(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $report = StatementFilterReport::query()->create([
            'team_id' => $user->currentTeam->id,
            'name' => 'Household',
        ]);
        $report->replaceRules([
            ['alias' => 'Woolies', 'match' => 'Woolworths'],
        ]);

        $this->actingAs($user)
            ->get(route('reports.statement-filters.show', [
                'statementFilterReport' => $report,
                'from' => '2018-01-01',
                'to' => '2026-03-01',
            ]))
            ->assertRedirect(route('reports.statement-filters.show', $report))
            ->assertSessionHas('error');
    }

    public function test_another_business_cannot_open_the_report(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $report = StatementFilterReport::query()->create([
            'team_id' => $owner->currentTeam->id,
            'name' => 'Private',
        ]);

        $other = User::factory()->withPersonalTeam()->create();

        $this->actingAs($other)
            ->get(route('reports.statement-filters.show', $report))
            ->assertNotFound();
    }

    public function test_suggestions_group_noisy_statement_lines_and_preview_counts_matches(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $account = BankingAccount::factory()->for($team)->create();

        $this->line($team->id, $account->id, '2026-03-04', 'POS PURCHASE WOOLWORTHS 5521', null, '400.00', TransactionDirection::Debit);
        $this->line($team->id, $account->id, '2026-03-18', 'Card purchase', 'Woolworths', '50.00', TransactionDirection::Credit);
        $this->line($team->id, $account->id, '2026-08-02', 'Woolworths Canal Walk 2026-08-02', null, '125.50', TransactionDirection::Debit);
        $this->line($team->id, $account->id, '2026-03-04', 'SHELL 4412 MENLYN', null, '300.00', TransactionDirection::Debit);
        $this->line($team->id, $account->id, '2026-04-04', 'Shell fuel', null, '200.00', TransactionDirection::Debit);
        $this->line($team->id, $account->id, '2026-03-09', 'RENT JANUARY', null, '9000.00', TransactionDirection::Debit);
        $this->line($team->id, $account->id, '2026-05-01', 'CHECKERS HYPER 12/03/2026', null, '80.00', TransactionDirection::Debit);
        $this->line($team->id, $account->id, '2026-05-02', 'POS PURCHASE CHECKERS 453212349999', null, '20.00', TransactionDirection::Debit);

        $other = User::factory()->withPersonalTeam()->create();
        $otherAccount = BankingAccount::factory()->for($other->currentTeam)->create();
        $this->line($other->currentTeam->id, $otherAccount->id, '2026-03-04', 'Woolworths other books', null, '999.00', TransactionDirection::Debit);
        $this->line($other->currentTeam->id, $otherAccount->id, '2026-03-05', 'Woolworths other books', null, '10.00', TransactionDirection::Debit);

        $this->actingAs($user)
            ->get(route('reports.statement-filters.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/StatementFilters/Form')
                ->where('suggestions.0.match', 'woolworths')
                ->where('suggestions.0.alias', 'Woolworths')
                ->where('suggestions.0.count', 3)
                ->where('suggestions.0.total_cents', 47550)
                ->where('suggestions.1.match', 'shell')
                ->where('suggestions.1.count', 2)
                ->where('suggestions.1.total_cents', 50000)
                ->where('suggestions.2.match', 'checkers')
                ->where('suggestions.2.count', 2)
                ->where('suggestions', fn ($suggestions) => collect($suggestions)->pluck('match')->doesntContain('rent')));

        $this->actingAs($user)
            ->getJson(route('reports.statement-filters.preview', ['match' => 'wool']))
            ->assertOk()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('total_cents', 47550);

        $this->actingAs($user)
            ->getJson(route('reports.statement-filters.preview', ['match' => 'wo']))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('samples', []);
    }

    public function test_viewer_cannot_preview_statement_matches(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        EnsureTeamSystemRoles::ensureFor($team);

        $role = TeamRole::query()->create([
            'team_id' => $team->id,
            'key' => 'reporter',
            'name' => 'Reporter',
            'description' => 'Reports only',
            'permissions' => ['reports.view'],
            'is_system' => false,
        ]);
        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => $role->key]);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($member)
            ->getJson(route('reports.statement-filters.preview', ['match' => 'woolworths']))
            ->assertForbidden();
    }

    private function line(
        int $teamId,
        int $accountId,
        string $date,
        string $description,
        ?string $reference,
        string $amount,
        TransactionDirection $direction,
    ): void {
        BankingTransaction::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            'account_id' => $accountId,
            'transaction_date' => $date,
            'description' => $description,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => 'ZAR',
            'direction' => $direction,
            'source_hash' => hash('sha256', $teamId.$date.$description.$amount.($reference ?? '')),
            'duplicate_key' => hash('sha256', 'key-'.$teamId.$date.$description.$amount.($reference ?? '')),
        ]);
    }
}
