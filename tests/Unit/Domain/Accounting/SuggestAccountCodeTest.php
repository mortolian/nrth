<?php

namespace Tests\Unit\Domain\Accounting;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\SuggestAccountCode;
use App\Models\User;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuggestAccountCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_type_base_when_chart_empty(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $suggest = new SuggestAccountCode;

        $this->assertSame('5000', $suggest->for($team->id, AccountType::Expense));
        $this->assertSame('1000', $suggest->for($team->id, AccountType::Asset));
    }

    public function test_suggests_next_code_after_existing_type_accounts(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        (new DefaultChartOfAccountsSeeder)->runForTeam($team);

        $suggest = new SuggestAccountCode;

        $this->assertSame('5910', $suggest->for($team->id, AccountType::Expense));
        $this->assertSame('1210', $suggest->for($team->id, AccountType::Asset));
    }

    public function test_suggests_next_sibling_under_parent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        (new DefaultChartOfAccountsSeeder)->runForTeam($team);

        $parent = Account::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('code', '1000')
            ->firstOrFail();

        $suggest = new SuggestAccountCode;

        $this->assertSame('1030', $suggest->for($team->id, AccountType::Asset, $parent->id));
    }
}
