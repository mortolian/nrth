<?php

namespace App\Modules\Wealth\Services;

use App\Modules\Wealth\Calculators\InvestmentMovementCalculator;
use App\Modules\Wealth\Enums\WealthAssetType;
use App\Modules\Wealth\Enums\WealthLiquidity;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthPortfolio;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class PortfolioPerformanceService
{
    public function __construct(
        private readonly InvestmentMovementCalculator $movement,
        private readonly PortfolioValuationService $valuations,
    ) {}

    /**
     * @return array{
     *     total_cents: int,
     *     accessible_cents: int,
     *     restricted_cents: int,
     *     currency: string,
     *     month: array<string, int|string>,
     *     financial_year: array<string, int|string>,
     *     by_asset_type: list<array{type: string, label: string, value_cents: int}>,
     *     by_owner: list<array{owner: string, value_cents: int}>,
     *     assets: list<array<string, mixed>>
     * }
     */
    public function overview(WealthPortfolio $portfolio, ?CarbonInterface $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $currency = $portfolio->base_currency;
        $assets = $portfolio->assets()->where('is_active', true)->orderBy('name')->get();
        $assets->each->setRelation('portfolio', $portfolio);

        $total = 0;
        $accessible = 0;
        $restricted = 0;
        $byType = [];
        $byOwner = [];

        $monthStart = $asOf->copy()->startOfMonth();
        [$fyStart, $fyEnd] = WealthFinancialYear::windowContaining($asOf, (int) $portfolio->financial_year_start_month);
        $fyPeriodEnd = $asOf->lt($fyEnd) ? $asOf : $fyEnd;

        $monthMovement = $this->movement->forAssets($assets, $monthStart, $asOf, $currency);
        $fyMovement = $this->movement->forAssets($assets, $fyStart, $fyPeriodEnd, $currency);

        $assetRows = [];
        foreach ($assets as $asset) {
            if ($asset->currency !== $currency) {
                continue;
            }

            $value = $asset->valueCentsAsOf($asOf);
            $total += $value;

            /** @var WealthLiquidity $liquidity */
            $liquidity = $asset->liquidity;
            if ($liquidity->isAccessible()) {
                $accessible += $value;
            } else {
                $restricted += $value;
            }

            $typeKey = $asset->asset_type->value;
            $byType[$typeKey] = ($byType[$typeKey] ?? 0) + $value;
            $owner = $asset->owner_name;
            $byOwner[$owner] = ($byOwner[$owner] ?? 0) + $value;

            $period = $this->movement->forAsset($asset, $monthStart, $asOf);
            $fy = $this->movement->forAsset($asset, $fyStart, $fyPeriodEnd);

            $assetRows[] = [
                'id' => $asset->id,
                'name' => $asset->name,
                'owner_name' => $asset->owner_name,
                'asset_type' => $asset->asset_type->value,
                'asset_type_label' => $asset->asset_type->label(),
                'institution' => $asset->institution,
                'liquidity' => $liquidity->value,
                'liquidity_label' => $liquidity->label(),
                'currency' => $asset->currency,
                'current_value_cents' => $value,
                'period_movement_cents' => $period['investment_movement_cents'],
                'financial_year_movement_cents' => $fy['investment_movement_cents'],
                'interest_rate_bps' => $asset->interest_rate_bps,
            ];
        }

        $byAssetType = [];
        foreach ($byType as $type => $cents) {
            $byAssetType[] = [
                'type' => $type,
                'label' => WealthAssetType::from($type)->label(),
                'value_cents' => $cents,
            ];
        }
        usort($byAssetType, fn ($a, $b) => $b['value_cents'] <=> $a['value_cents']);

        $byOwnerRows = [];
        foreach ($byOwner as $owner => $cents) {
            $byOwnerRows[] = ['owner' => $owner, 'value_cents' => $cents];
        }
        usort($byOwnerRows, fn ($a, $b) => $b['value_cents'] <=> $a['value_cents']);

        return [
            'total_cents' => $total,
            'accessible_cents' => $accessible,
            'restricted_cents' => $restricted,
            'currency' => $currency,
            'month' => $monthMovement,
            'financial_year' => [
                ...$fyMovement,
                'label' => WealthFinancialYear::labelForWindow($fyStart, $fyEnd),
                'starts_on' => $fyStart->toDateString(),
                'ends_on' => $fyEnd->toDateString(),
            ],
            'by_asset_type' => $byAssetType,
            'by_owner' => $byOwnerRows,
            'assets' => $assetRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assetDetail(WealthAsset $asset, ?CarbonInterface $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $portfolio = $asset->portfolio;
        [$fyStart, $fyEnd] = WealthFinancialYear::windowContaining($asOf, (int) $portfolio->financial_year_start_month);
        $fyPeriodEnd = $asOf->lt($fyEnd) ? $asOf : $fyEnd;

        $fy = $this->movement->forAsset($asset, $fyStart, $fyPeriodEnd);
        $startMonth = (int) $portfolio->financial_year_start_month;

        $allValuations = $asset->valuations()
            ->reorder()
            ->orderBy('valued_on')
            ->orderBy('id')
            ->get();

        $chartPoints = [];
        $valuationChangeById = [];
        $previousCentsInYear = [];
        $previousGlobalCents = null;

        foreach ($allValuations as $valuation) {
            [$rowStart, $rowEnd] = WealthFinancialYear::windowContaining($valuation->valued_on, $startMonth);
            $yearLabel = WealthFinancialYear::labelForWindow($rowStart, $rowEnd);
            $valueCents = (int) $valuation->value_cents;

            // Table change is scoped to the financial year so sparse yearly snapshots
            // are not compared to the prior year's last point as if they were monthly moves.
            $previousInYearCents = $previousCentsInYear[$yearLabel] ?? null;
            $yearChangeCents = $previousInYearCents === null ? null : $valueCents - $previousInYearCents;
            $valuationChangeById[$valuation->id] = [
                'change_cents' => $yearChangeCents,
                'change_percent' => $this->changePercent($yearChangeCents, $previousInYearCents),
                'year_label' => $yearLabel,
            ];
            $previousCentsInYear[$yearLabel] = $valueCents;

            $globalChangeCents = $previousGlobalCents === null ? null : $valueCents - $previousGlobalCents;
            $chartPoints[] = [
                'date' => $valuation->valued_on->toDateString(),
                'label' => $valuation->valued_on->format('d M Y'),
                'value_cents' => $valueCents,
                'change_cents' => $globalChangeCents,
                'change_percent' => $this->changePercent($globalChangeCents, $previousGlobalCents),
            ];
            $previousGlobalCents = $valueCents;
        }

        $valuationRows = $allValuations
            ->sortByDesc(fn ($valuation) => $valuation->valued_on->timestamp.'-'.$valuation->id)
            ->values()
            ->map(function ($valuation) use ($valuationChangeById) {
                $change = $valuationChangeById[$valuation->id] ?? [
                    'change_cents' => null,
                    'change_percent' => null,
                    'year_label' => '',
                ];

                return [
                    'id' => $valuation->id,
                    'valued_on' => $valuation->valued_on->toDateString(),
                    'value_cents' => (int) $valuation->value_cents,
                    'change_cents' => $change['change_cents'],
                    'change_percent' => $change['change_percent'],
                    'year_label' => $change['year_label'],
                    'currency' => $valuation->currency,
                    'notes' => $valuation->notes,
                    'source' => $valuation->source->value,
                ];
            })
            ->all();

        $transactionRows = $asset->transactions()
            ->reorder()
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get()
            ->map(function ($transaction) use ($startMonth) {
                [$rowStart, $rowEnd] = WealthFinancialYear::windowContaining($transaction->occurred_on, $startMonth);

                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type->value,
                    'type_label' => $transaction->type->label(),
                    'occurred_on' => $transaction->occurred_on->toDateString(),
                    'amount_cents' => (int) $transaction->amount_cents,
                    'signed_amount_cents' => $transaction->signedFlowCents(),
                    'year_label' => WealthFinancialYear::labelForWindow($rowStart, $rowEnd),
                    'currency' => $transaction->currency,
                    'notes' => $transaction->notes,
                    'source' => $transaction->source->value,
                ];
            })
            ->all();

        return [
            'asset' => [
                'id' => $asset->id,
                'name' => $asset->name,
                'owner_name' => $asset->owner_name,
                'asset_type' => $asset->asset_type->value,
                'asset_type_label' => $asset->asset_type->label(),
                'institution' => $asset->institution,
                'currency' => $asset->currency,
                'liquidity' => $asset->liquidity->value,
                'liquidity_label' => $asset->liquidity->label(),
                'interest_rate_bps' => $asset->interest_rate_bps,
                'notes' => $asset->notes,
                'is_active' => $asset->is_active,
                'portfolio_id' => $asset->portfolio_id,
            ],
            'current_value_cents' => $asset->valueCentsAsOf($asOf),
            'financial_year' => [
                ...$fy,
                'label' => WealthFinancialYear::labelForWindow($fyStart, $fyEnd),
                'starts_on' => $fyStart->toDateString(),
                'ends_on' => $fyEnd->toDateString(),
            ],
            'valuations' => $valuationRows,
            'transactions' => $transactionRows,
            'chart' => $chartPoints,
            'yearly_summaries' => $this->yearlySummaries($asset, $asOf),
        ];
    }

    /**
     * One row per financial year from the earliest activity through $asOf.
     *
     * @return list<array{
     *     label: string,
     *     starts_on: string,
     *     ends_on: string,
     *     as_of: string,
     *     is_current: bool,
     *     opening_cents: int,
     *     closing_cents: int,
     *     contributions_cents: int,
     *     withdrawals_cents: int,
     *     investment_movement_cents: int,
     *     opening_as_of: string|null,
     *     used_synthetic_opening: bool
     * }>
     */
    private function yearlySummaries(WealthAsset $asset, CarbonInterface $asOf): array
    {
        $startMonth = (int) $asset->portfolio->financial_year_start_month;

        $earliestValuation = $asset->valuations()->reorder()->orderBy('valued_on')->value('valued_on');
        $earliestTransaction = $asset->transactions()->reorder()->orderBy('occurred_on')->value('occurred_on');

        $dates = array_values(array_filter([$earliestValuation, $earliestTransaction]));
        if ($dates === []) {
            return [];
        }

        $earliest = collect($dates)
            ->map(fn ($date) => Carbon::parse($date)->startOfDay())
            ->sort()
            ->first();

        [$firstStart] = WealthFinancialYear::windowContaining($earliest, $startMonth);
        $rows = [];
        $fyStart = $firstStart->copy();

        while ($fyStart->lte($asOf)) {
            [, $fyEnd] = WealthFinancialYear::windowContaining($fyStart, $startMonth);
            $periodEnd = $fyEnd->gt($asOf) ? $asOf->copy() : $fyEnd->copy();
            $movement = $this->movement->forAsset($asset, $fyStart, $periodEnd);

            $rows[] = [
                'label' => WealthFinancialYear::labelForWindow($fyStart, $fyEnd),
                'starts_on' => $fyStart->toDateString(),
                'ends_on' => $fyEnd->toDateString(),
                'as_of' => $periodEnd->toDateString(),
                'is_current' => $fyEnd->gt($asOf),
                'opening_cents' => $movement['opening_cents'],
                'closing_cents' => $movement['closing_cents'],
                'contributions_cents' => $movement['contributions_cents'],
                'withdrawals_cents' => $movement['withdrawals_cents'],
                'investment_movement_cents' => $movement['investment_movement_cents'],
                'opening_as_of' => $movement['opening_as_of'],
                'used_synthetic_opening' => $movement['used_synthetic_opening'],
            ];

            $fyStart = $fyStart->copy()->addYear();
        }

        return array_reverse($rows);
    }

    private function changePercent(?int $changeCents, ?int $previousCents): ?float
    {
        if ($changeCents === null || $previousCents === null || $previousCents === 0) {
            return null;
        }

        return round(($changeCents / $previousCents) * 100, 2);
    }
}
