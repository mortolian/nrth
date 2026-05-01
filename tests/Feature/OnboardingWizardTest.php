<?php

namespace Tests\Feature;

use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\Transaction;
use App\Models\TeamBankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_onboarding(): void
    {
        $this->get(route('onboarding.setup'))->assertRedirect();
    }

    public function test_incomplete_user_is_redirected_from_dashboard_to_onboarding(): void
    {
        $user = User::factory()->withPersonalTeam()->withoutCompletedOnboarding()->create();

        $this->actingAs($user);

        $this->get(route('dashboard'))->assertRedirect(route('onboarding.setup'));
    }

    public function test_completed_user_can_view_dashboard(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_completed_user_visiting_onboarding_is_redirected_to_dashboard(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user);

        $this->get(route('onboarding.setup'))->assertRedirect(route('dashboard'));
    }

    public function test_skip_marks_onboarding_complete_and_seeds_chart(): void
    {
        $user = User::factory()->withPersonalTeam()->withoutCompletedOnboarding()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        Account::queryWithoutTeamScope()->where('team_id', $team->id)->delete();

        $this->actingAs($user);

        $this->post(route('onboarding.skip'))->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->completed_onboarding_at);
        $this->assertTrue(
            Account::queryWithoutTeamScope()->where('team_id', $team->id)->exists()
        );
    }

    public function test_complete_updates_team_and_posts_opening_balance_when_requested(): void
    {
        $user = User::factory()->withPersonalTeam()->withoutCompletedOnboarding()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $this->actingAs($user);

        $this->post(route('onboarding.complete'), [
            'company_name' => 'Acme Trading (Pty) Ltd',
            'vat_registered' => '0',
            'vat_number' => '',
            'financial_year_end_month' => '2',
            'industry' => 'retail',
            'has_existing_books' => '1',
            'opening_bank' => '10000.50',
            'opening_ar' => '2500',
            'opening_ap' => '1500',
            'invoice_default_payment_terms_days' => '14',
            'invoice_prefix' => 'INV',
            'invoice_next_sequence' => '7',
            'bank_name' => 'Test Bank',
            'bank_account_holder' => 'Acme Trading',
            'bank_account_number' => '1234567890',
            'bank_branch_code' => '250655',
            'bank_account_type' => 'current',
        ])->assertRedirect(route('dashboard'));

        $team->refresh();
        $this->assertSame('Acme Trading (Pty) Ltd', $team->name);
        $this->assertSame('retail', $team->mergedCompanySettings()['industry']);
        $this->assertNotNull($user->fresh()->completed_onboarding_at);

        $transaction = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('type', TransactionType::OpeningBalance)
            ->where('reference', 'OB-SETUP')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertSame(TransactionStatus::Posted, $transaction->status);

        $this->assertDatabaseHas('team_bank_accounts', [
            'team_id' => $team->id,
            'bank_name' => 'Test Bank',
            'bank_account_number' => '1234567890',
            'show_on_invoice' => true,
        ]);
        $this->assertSame(1, TeamBankAccount::query()->where('team_id', $team->id)->count());
    }
}
