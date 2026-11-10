<?php

namespace App\Domain\SampleData;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\TaxLine;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Actions\EnsureDefaultBankingAccount;
use App\Domain\Expenses\Models\RecurringExpense;
use App\Domain\Invoicing\Models\Estimate;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\RecurringInvoice;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Database\Seeders\DefaultTaxRatesSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class SampleBusiness
{
    public function install(SampleCalendar $calendar): SampleContext
    {
        return DB::transaction(function () use ($calendar): SampleContext {
            $this->forget();

            $user = User::factory()->create([
                'name' => 'Alex North',
                'email' => SampleData::EMAIL,
                'password' => Hash::make(SampleData::PASSWORD),
                'email_verified_at' => now(),
                'completed_onboarding_at' => now(),
            ]);

            $settings = array_merge(Team::defaultBusinessSettings(), [
                'trading_name' => SampleData::BUSINESS,
                'registration_number' => '2024/123456/07',
                'vat_number' => '4123456789',
                'vat_registered' => true,
                'default_vat_rate' => 0.15,
                'vat_period_type' => 'bi_monthly',
                'financial_year_end_month' => SampleData::FINANCIAL_YEAR_END_MONTH,
                'industry' => 'Design studio',
                'physical_street' => '18 Bree Street',
                'physical_city' => 'Cape Town',
                'physical_province' => 'Western Cape',
                'physical_postal_code' => '8001',
                'physical_country' => 'South Africa',
                'business_email' => 'studio@northwind.example',
                'business_phone' => '021 555 0148',
                'invoice_prefix' => 'INV',
                'estimate_prefix' => 'EST',
            ]);

            $team = $user->ownedTeams()->create([
                'name' => SampleData::BUSINESS,
                'personal_team' => true,
                'business_settings' => $settings,
            ]);
            $user->switchTeam($team);

            foreach (['travel', 'planning', 'wealth'] as $module) {
                $team->setModuleEnabled($module, true);
            }

            (new DefaultChartOfAccountsSeeder)->runForTeam($team);
            (new DefaultTaxRatesSeeder)->runForTeam($team);

            $bank = (new EnsureDefaultBankingAccount)->execute($team->fresh());
            $bank->forceFill([
                'name' => 'FNB Cheque',
                'bank_name' => 'First National Bank',
                'account_number_last4' => '4821',
                'type' => 'cheque',
            ])->save();

            Auth::login($user);

            $accounts = Account::queryWithoutTeamScope()
                ->where('team_id', $team->id)
                ->get()
                ->keyBy('code')
                ->all();

            return new SampleContext($user->fresh(), $team->fresh(), $calendar, $bank->fresh(), [], $accounts);
        });
    }

    /**
     * Delete the sample user and every business they own. Other businesses stay.
     */
    public function forget(): void
    {
        $user = User::query()->where('email', SampleData::EMAIL)->first();
        if ($user === null) {
            return;
        }

        foreach ($user->ownedTeams()->get() as $team) {
            $this->deleteBooks($team);
            $team->purge();
        }

        $user->teams()->detach();
        $user->forceFill(['current_team_id' => null])->save();
        $user->delete();
    }

    private function deleteBooks(Team $team): void
    {
        $transactionIds = Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->pluck('id');

        if ($transactionIds->isNotEmpty()) {
            JournalEntry::query()->whereIn('transaction_id', $transactionIds)->delete();
            TaxLine::query()->whereIn('transaction_id', $transactionIds)->delete();
        }

        Invoice::queryWithoutTeamScope()->where('team_id', $team->id)->delete();
        Estimate::queryWithoutTeamScope()->where('team_id', $team->id)->delete();
        RecurringInvoice::queryWithoutTeamScope()->where('team_id', $team->id)->delete();
        RecurringExpense::queryWithoutTeamScope()->where('team_id', $team->id)->delete();
        Transaction::queryWithoutTeamScope()->where('team_id', $team->id)->delete();
    }
}
