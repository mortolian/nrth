<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Support\FinancialYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoiceIndexFyPaidSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function actingTeamContext(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
    }

    public function test_invoice_index_includes_fy_paid_income_from_payments_in_current_fy(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_merge($team->mergedBusinessSettings(), [
                'financial_year_end_month' => 2,
                'invoice_default_currency' => 'ZAR',
            ]),
        ])->save();
        $this->actingTeamContext($user, $team);

        $endMonth = 2;
        $startMonth = FinancialYear::startMonthFromEndMonth($endMonth);
        [$fyStart, $fyEnd] = FinancialYear::windowContaining(now(), $startMonth);

        $client = Client::factory()->for($team)->create();
        $invoice = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Partial,
            'currency' => 'ZAR',
            'total_cents' => 500_00,
            'amount_paid_cents' => 350_00,
        ]);

        Payment::factory()->for($team)->for($invoice)->create([
            'amount_cents' => 200_00,
            'currency' => 'ZAR',
            'payment_date' => $fyStart->copy()->addMonth()->toDateString(),
            'method' => PaymentMethod::Eft,
        ]);
        Payment::factory()->for($team)->for($invoice)->create([
            'amount_cents' => 150_00,
            'currency' => 'ZAR',
            'payment_date' => $fyEnd->copy()->subDay()->toDateString(),
            'method' => PaymentMethod::Eft,
        ]);
        // Outside current FY — must not count.
        Payment::factory()->for($team)->for($invoice)->create([
            'amount_cents' => 999_00,
            'currency' => 'ZAR',
            'payment_date' => $fyStart->copy()->subDay()->toDateString(),
            'method' => PaymentMethod::Eft,
        ]);

        $this->get(route('invoicing.invoices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invoicing/Invoices/Index')
                ->where('summary.fy_label', FinancialYear::labelForWindow($fyStart, $fyEnd))
                ->where('summary.fy_paid_currency', 'ZAR')
                ->where('summary.fy_paid_total', 350_00)
            );
    }

    public function test_fy_paid_income_converts_foreign_payments_to_business_currency(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $team->forceFill([
            'business_settings' => array_merge($team->mergedBusinessSettings(), [
                'financial_year_end_month' => 2,
                'invoice_default_currency' => 'ZAR',
            ]),
        ])->save();
        $this->actingTeamContext($user, $team);

        $startMonth = FinancialYear::startMonthFromEndMonth(2);
        [$fyStart] = FinancialYear::windowContaining(now(), $startMonth);

        $client = Client::factory()->for($team)->create();
        $invoice = Invoice::factory()->for($team)->for($client)->create([
            'status' => InvoiceStatus::Partial,
            'currency' => 'USD',
            'total_cents' => 100_00,
            'amount_paid_cents' => 100_00,
            'business_currency_code' => 'ZAR',
            'fx_rate_invoice_to_business' => '18',
            'total_business_currency_cents' => 1800_00,
        ]);

        Payment::factory()->for($team)->for($invoice)->create([
            'amount_cents' => 100_00,
            'currency' => 'USD',
            'bank_amount_business_cents' => 1700_00,
            'payment_date' => $fyStart->copy()->addDays(10)->toDateString(),
            'method' => PaymentMethod::Eft,
        ]);

        $this->get(route('invoicing.invoices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invoicing/Invoices/Index')
                ->where('summary.fy_paid_currency', 'ZAR')
                ->where('summary.fy_paid_total', 1700_00)
            );
    }
}
