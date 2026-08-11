<?php

namespace App\Modules\Wealth\Services;

use App\Modules\Wealth\Calculators\InvestmentMovementCalculator;
use App\Modules\Wealth\Enums\WealthAssetType;
use App\Modules\Wealth\Enums\WealthLiquidity;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthPortfolio;
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
        $valuations = $asset->valuations()->reorder()->orderByDesc('valued_on')->orderByDesc('id')->limit(60)->get();
        $transactions = $asset->transactions()->reorder()->orderByDesc('occurred_on')->orderByDesc('id')->limit(60)->get();

        $chartPoints = $asset->valuations()
            ->reorder()
            ->orderBy('valued_on')
            ->get()
            ->map(fn ($v) => [
                'date' => $v->valued_on->toDateString(),
                'label' => $v->valued_on->format('d M Y'),
                'value_cents' => (int) $v->value_cents,
            ])
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
            'valuations' => $valuations->map(fn ($v) => [
                'id' => $v->id,
                'valued_on' => $v->valued_on->toDateString(),
                'value_cents' => (int) $v->value_cents,
                'currency' => $v->currency,
                'notes' => $v->notes,
                'source' => $v->source->value,
            ])->all(),
            'transactions' => $transactions->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type->value,
                'type_label' => $t->type->label(),
                'occurred_on' => $t->occurred_on->toDateString(),
                'amount_cents' => (int) $t->amount_cents,
                'currency' => $t->currency,
                'notes' => $t->notes,
                'source' => $t->source->value,
            ])->all(),
            'chart' => $chartPoints,
        ];
    }
}
