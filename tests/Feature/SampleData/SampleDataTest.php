<?php

namespace Tests\Feature\SampleData;

use App\Domain\Banking\Enums\ReconciliationStatus;
use App\Domain\Banking\Models\BankingTransaction;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Reports\Models\StatementFilterReport;
use App\Domain\SampleData\SampleCalendar;
use App\Domain\SampleData\SampleData;
use App\Domain\SampleData\SampleDataRunner;
use App\Domain\Tax\Models\VATReturn;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleDataTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_calendar_posts_up_to_today_and_can_close_the_previous_year(): void
    {
        $current = SampleCalendar::make(false, Carbon::parse('2026-11-10'));

        $this->assertSame('2026-03-01', $current->start->toDateString());
        $this->assertSame('2027-02-28', $current->end->toDateString());
        $this->assertSame('2026-11-10', $current->asOf->toDateString());
        $this->assertCount(9, $current->postedMonths());
        $this->assertCount(3, $current->futureMonths());

        $previous = SampleCalendar::make(true, Carbon::parse('2026-11-10'));

        $this->assertSame('2025-03-01', $previous->start->toDateString());
        $this->assertSame('2026-02-28', $previous->end->toDateString());
        $this->assertSame('2026-02-28', $previous->asOf->toDateString());
        $this->assertCount(12, $previous->postedMonths());
        $this->assertSame([], $previous->futureMonths());
    }

    public function test_sample_business_covers_the_year_and_can_be_replaced(): void
    {
        Carbon::setTestNow('2026-11-10');

        $other = User::factory()->withPersonalTeam()->create();
        $otherClient = Client::query()->create([
            'team_id' => $other->currentTeam->id,
            'name' => 'Real Client',
            'currency' => 'ZAR',
            'payment_terms_days' => 30,
            'is_active' => true,
        ]);

        $run = app(SampleDataRunner::class)->run();

        $this->assertLessThan(30_000, $run->milliseconds);
        $this->assertSame(SampleData::EMAIL, $run->context->user->email);
        $this->assertTrue($run->context->team->moduleEnabled('travel'));
        $this->assertTrue($run->context->team->moduleEnabled('planning'));
        $this->assertTrue($run->context->team->moduleEnabled('wealth'));
        $this->assertGreaterThanOrEqual(9, $run->counts['invoices']);
        $this->assertGreaterThanOrEqual(27, $run->counts['expenses']);
        $this->assertGreaterThanOrEqual(50, $run->counts['statement_lines']);
        $this->assertSame(1, $run->counts['statement_filters']);

        $teamId = $run->context->team->id;
        $this->assertGreaterThan(0, Invoice::queryWithoutTeamScope()->where('team_id', $teamId)->where('status', InvoiceStatus::Paid)->count());
        $this->assertGreaterThan(0, Invoice::queryWithoutTeamScope()->where('team_id', $teamId)->where('status', InvoiceStatus::Sent)->count());
        $this->assertGreaterThan(0, Invoice::queryWithoutTeamScope()->where('team_id', $teamId)->where('status', InvoiceStatus::Partial)->count());
        $this->assertGreaterThan(0, Invoice::queryWithoutTeamScope()->where('team_id', $teamId)->where('status', InvoiceStatus::Draft)->count());
        $this->assertSame(1, BankingTransaction::queryWithoutTeamScope()->where('team_id', $teamId)->where('reconciliation_status', ReconciliationStatus::Excluded)->count());
        $this->assertNotNull(StatementFilterReport::queryWithoutTeamScope()->where('team_id', $teamId)->where('name', 'Card spend')->first());
        $this->assertGreaterThan(0, VATReturn::queryWithoutTeamScope()->where('team_id', $teamId)->count());

        $again = app(SampleDataRunner::class)->run();

        $this->assertSame(1, User::query()->where('email', SampleData::EMAIL)->count());
        $this->assertSame($run->counts['invoices'], $again->counts['invoices']);
        $this->assertNotNull(Client::queryWithoutTeamScope()->find($otherClient->id));
        $this->assertNotSame($teamId, $again->context->team->id);
    }

    public function test_previous_year_posts_every_month(): void
    {
        Carbon::setTestNow('2026-11-10');

        $run = app(SampleDataRunner::class)->run(previousYear: true);

        $this->assertSame('2026-02-28', $run->context->calendar->asOf->toDateString());
        $this->assertGreaterThanOrEqual(12, $run->counts['invoices']);
        $this->assertSame(0, Invoice::queryWithoutTeamScope()
            ->where('team_id', $run->context->team->id)
            ->where('status', InvoiceStatus::Draft)
            ->count());
    }

    public function test_production_refuses_without_force(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('app:sample-data')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => SampleData::EMAIL]);
    }
}
