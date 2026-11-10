<?php

namespace App\Domain\SampleData\Chapters;

use App\Domain\SampleData\SampleChapter;
use App\Domain\SampleData\SampleContext;
use App\Modules\Wealth\Enums\WealthAssetType;
use App\Modules\Wealth\Enums\WealthLiquidity;
use App\Modules\Wealth\Enums\WealthTransactionType;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthContributionAllowance;
use App\Modules\Wealth\Models\WealthPortfolio;
use App\Modules\Wealth\Services\AssetTransactionService;
use App\Modules\Wealth\Services\AssetValuationService;
use App\Support\FinancialYear;

final class WealthChapter implements SampleChapter
{
    public function key(): string
    {
        return 'wealth';
    }

    public function seed(SampleContext $context): void
    {
        $portfolio = WealthPortfolio::query()->create([
            'team_id' => $context->team->id,
            'name' => 'North',
            'base_currency' => 'ZAR',
            'financial_year_start_month' => FinancialYear::startMonthFromEndMonth(2),
            'is_default' => true,
            'notes' => 'Personal investments kept apart from the studio books.',
        ]);

        $retirement = WealthAsset::query()->create([
            'team_id' => $context->team->id,
            'portfolio_id' => $portfolio->id,
            'name' => 'Retirement annuity',
            'owner_name' => 'Alex North',
            'asset_type' => WealthAssetType::RetirementFund,
            'institution' => 'Allan Gray',
            'currency' => 'ZAR',
            'liquidity' => WealthLiquidity::Retirement,
            'is_active' => true,
        ]);

        $savings = WealthAsset::query()->create([
            'team_id' => $context->team->id,
            'portfolio_id' => $portfolio->id,
            'name' => 'Tax-free savings',
            'owner_name' => 'Alex North',
            'asset_type' => WealthAssetType::TaxFreeSavings,
            'institution' => 'EasyEquities',
            'currency' => 'ZAR',
            'liquidity' => WealthLiquidity::Accessible,
            'is_active' => true,
        ]);

        $valuations = app(AssetValuationService::class);
        $transactions = app(AssetTransactionService::class);

        $valuations->record($retirement, $context->calendar->start, 86_000_000, 'Opening value');
        $valuations->record($savings, $context->calendar->start, 4_200_000, 'Opening value');

        $retirementValue = 86_000_000;
        $savingsValue = 4_200_000;
        foreach ($context->calendar->postedMonths() as $month) {
            $date = $month->copy()->day(min(15, $month->daysInMonth));
            if ($date->greaterThan($context->calendar->asOf)) {
                $date = $context->calendar->asOf->copy();
            }

            $transactions->record($retirement, WealthTransactionType::Contribution, $date, 500_000, 'Monthly contribution');
            $retirementValue += 500_000 + 40_000;
            $transactions->record($savings, WealthTransactionType::Contribution, $date, 300_000, 'Monthly contribution');
            $savingsValue += 300_000 + 15_000;
        }

        $valuations->record($retirement, $context->calendar->asOf, $retirementValue, 'Latest statement');
        $valuations->record($savings, $context->calendar->asOf, $savingsValue, 'Latest statement');

        WealthContributionAllowance::query()->create([
            'team_id' => $context->team->id,
            'portfolio_id' => $portfolio->id,
            'asset_id' => $retirement->id,
            'owner_name' => 'Alex North',
            'label' => 'Retirement annuity',
            'scheme_key' => 'retirement_annuity',
            'financial_year_label' => $context->calendar->label(),
            'year_starts_on' => $context->calendar->start->toDateString(),
            'year_ends_on' => $context->calendar->end->toDateString(),
            'limit_cents' => 35_000_000,
            'currency' => 'ZAR',
        ]);
    }
}
