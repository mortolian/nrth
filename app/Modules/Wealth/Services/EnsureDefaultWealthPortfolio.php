<?php

namespace App\Modules\Wealth\Services;

use App\Models\Team;
use App\Modules\Wealth\Models\WealthPortfolio;
use App\Support\Iso4217Currencies;

final class EnsureDefaultWealthPortfolio
{
    public function forTeam(Team $team): WealthPortfolio
    {
        $existing = WealthPortfolio::query()
            ->where('team_id', $team->id)
            ->where('is_default', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $any = WealthPortfolio::query()->where('team_id', $team->id)->first();
        if ($any !== null) {
            $any->forceFill(['is_default' => true])->save();

            return $any;
        }

        $settings = $team->mergedBusinessSettings();
        $currency = Iso4217Currencies::normalize((string) ($settings['invoice_default_currency'] ?? 'ZAR'));
        $endMonth = (int) ($settings['financial_year_end_month'] ?? 2);
        $startMonth = WealthFinancialYear::startMonthFromEndMonth($endMonth);

        return WealthPortfolio::query()->create([
            'team_id' => $team->id,
            'name' => 'Wealth',
            'base_currency' => $currency,
            'financial_year_start_month' => $startMonth,
            'is_default' => true,
        ]);
    }
}
