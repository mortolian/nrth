<?php

namespace App\Modules\Wealth\Calculators;

use App\Modules\Wealth\Enums\WealthTransactionType;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthAssetTransaction;
use App\Modules\Wealth\Models\WealthAssetValuation;
use App\Modules\Wealth\Services\WealthFinancialYear;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class InvestmentMovementCalculator
{
    /**
     * Investment movement for a single asset over [periodStart, periodEnd].
     *
     * Opening is the last valuation dated in the previous financial year (on or before
     * the day before periodStart). Ancient carry-forward across missing years is not
     * used — otherwise a sparse history dumps multi-year gains into the current FY.
     *
     * When there is no prior-FY valuation, opening is the first valuation inside the
     * period (synthetic open), and only flows on/after that date are included.
     *
     * @return array{
     *     opening_cents: int,
     *     closing_cents: int,
     *     contributions_cents: int,
     *     withdrawals_cents: int,
     *     investment_movement_cents: int,
     *     currency: string,
     *     opening_as_of: string|null,
     *     used_synthetic_opening: bool
     * }
     */
    public function forAsset(WealthAsset $asset, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $currency = $asset->currency;
        $openingDate = $periodStart->copy()->subDay()->startOfDay();
        $startMonth = (int) ($asset->portfolio?->financial_year_start_month
            ?? $asset->portfolio()->value('financial_year_start_month')
            ?? 3);

        [$previousFyStart] = WealthFinancialYear::windowContaining($openingDate, $startMonth);

        $prior = $asset->valuationAsOf($openingDate);
        $priorInPreviousFy = $prior !== null
            && $prior->valued_on->greaterThanOrEqualTo($previousFyStart->copy()->startOfDay())
            && $prior->valued_on->lessThanOrEqualTo($openingDate);

        $usedSyntheticOpening = false;
        $openingAsOf = null;
        $flowStart = $periodStart->copy()->startOfDay();

        if ($priorInPreviousFy) {
            $opening = (int) $prior->value_cents;
            $openingAsOf = $prior->valued_on->toDateString();
        } else {
            $firstInPeriod = WealthAssetValuation::query()
                ->where('asset_id', $asset->id)
                ->whereDate('valued_on', '>=', $periodStart->toDateString())
                ->whereDate('valued_on', '<=', $periodEnd->toDateString())
                ->orderBy('valued_on')
                ->orderBy('id')
                ->first();

            if ($firstInPeriod !== null) {
                $opening = (int) $firstInPeriod->value_cents;
                $openingAsOf = $firstInPeriod->valued_on->toDateString();
                $flowStart = $firstInPeriod->valued_on->copy()->startOfDay();
                $usedSyntheticOpening = true;
            } else {
                $opening = 0;
            }
        }

        $closing = $asset->valueCentsAsOf($periodEnd);
        $flows = $this->periodFlows($asset, $flowStart, $periodEnd);

        $investmentMovement = $closing - $opening - $flows['contributions_cents'] + $flows['withdrawals_cents'];

        return [
            'opening_cents' => $opening,
            'closing_cents' => $closing,
            'contributions_cents' => $flows['contributions_cents'],
            'withdrawals_cents' => $flows['withdrawals_cents'],
            'investment_movement_cents' => $investmentMovement,
            'currency' => $currency,
            'opening_as_of' => $openingAsOf,
            'used_synthetic_opening' => $usedSyntheticOpening,
        ];
    }

    /**
     * @param  Collection<int, WealthAsset>  $assets
     * @return array{
     *     opening_cents: int,
     *     closing_cents: int,
     *     contributions_cents: int,
     *     withdrawals_cents: int,
     *     investment_movement_cents: int,
     *     currency: string
     * }
     */
    public function forAssets(Collection $assets, CarbonInterface $periodStart, CarbonInterface $periodEnd, string $currency): array
    {
        $opening = 0;
        $closing = 0;
        $contributions = 0;
        $withdrawals = 0;

        foreach ($assets as $asset) {
            if ($asset->currency !== $currency) {
                continue;
            }
            $row = $this->forAsset($asset, $periodStart, $periodEnd);
            $opening += $row['opening_cents'];
            $closing += $row['closing_cents'];
            $contributions += $row['contributions_cents'];
            $withdrawals += $row['withdrawals_cents'];
        }

        return [
            'opening_cents' => $opening,
            'closing_cents' => $closing,
            'contributions_cents' => $contributions,
            'withdrawals_cents' => $withdrawals,
            'investment_movement_cents' => $closing - $opening - $contributions + $withdrawals,
            'currency' => $currency,
        ];
    }

    /**
     * Trailing year (asOf − 1 year → asOf). Opening uses the last valuation on or before
     * the day before the window starts (carry-forward allowed — unlike financial-year
     * movement, which refuses ancient openings). Synthetic open when none exists.
     *
     * @param  Collection<int, WealthAsset>  $assets
     * @return array{
     *     opening_cents: int,
     *     closing_cents: int,
     *     contributions_cents: int,
     *     withdrawals_cents: int,
     *     investment_movement_cents: int,
     *     change_percent: float|null,
     *     currency: string,
     *     starts_on: string,
     *     ends_on: string,
     *     used_synthetic_opening: bool
     * }
     */
    public function forAssetsTrailingYear(Collection $assets, CarbonInterface $asOf, string $currency): array
    {
        $periodEnd = $asOf->copy()->startOfDay();
        $periodStart = $periodEnd->copy()->subYear();

        $opening = 0;
        $closing = 0;
        $contributions = 0;
        $withdrawals = 0;
        $usedSyntheticOpening = false;

        foreach ($assets as $asset) {
            if ($asset->currency !== $currency) {
                continue;
            }

            $row = $this->forAssetTrailingYear($asset, $periodStart, $periodEnd);
            $opening += $row['opening_cents'];
            $closing += $row['closing_cents'];
            $contributions += $row['contributions_cents'];
            $withdrawals += $row['withdrawals_cents'];
            $usedSyntheticOpening = $usedSyntheticOpening || $row['used_synthetic_opening'];
        }

        $movement = $closing - $opening - $contributions + $withdrawals;

        return [
            'opening_cents' => $opening,
            'closing_cents' => $closing,
            'contributions_cents' => $contributions,
            'withdrawals_cents' => $withdrawals,
            'investment_movement_cents' => $movement,
            'change_percent' => $opening !== 0 ? round(($movement / $opening) * 100, 2) : null,
            'currency' => $currency,
            'starts_on' => $periodStart->toDateString(),
            'ends_on' => $periodEnd->toDateString(),
            'used_synthetic_opening' => $usedSyntheticOpening,
        ];
    }

    /**
     * @return array{
     *     opening_cents: int,
     *     closing_cents: int,
     *     contributions_cents: int,
     *     withdrawals_cents: int,
     *     investment_movement_cents: int,
     *     currency: string,
     *     opening_as_of: string|null,
     *     used_synthetic_opening: bool
     * }
     */
    public function forAssetTrailingYear(WealthAsset $asset, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $currency = $asset->currency;
        $openingDate = $periodStart->copy()->subDay()->startOfDay();
        $prior = $asset->valuationAsOf($openingDate);

        $usedSyntheticOpening = false;
        $openingAsOf = null;
        $flowStart = $periodStart->copy()->startOfDay();

        if ($prior !== null) {
            $opening = (int) $prior->value_cents;
            $openingAsOf = $prior->valued_on->toDateString();
        } else {
            $firstInPeriod = WealthAssetValuation::query()
                ->where('asset_id', $asset->id)
                ->whereDate('valued_on', '>=', $periodStart->toDateString())
                ->whereDate('valued_on', '<=', $periodEnd->toDateString())
                ->orderBy('valued_on')
                ->orderBy('id')
                ->first();

            if ($firstInPeriod !== null) {
                $opening = (int) $firstInPeriod->value_cents;
                $openingAsOf = $firstInPeriod->valued_on->toDateString();
                $flowStart = $firstInPeriod->valued_on->copy()->startOfDay();
                $usedSyntheticOpening = true;
            } else {
                $opening = 0;
            }
        }

        $closing = $asset->valueCentsAsOf($periodEnd);
        $flows = $this->periodFlows($asset, $flowStart, $periodEnd);
        $investmentMovement = $closing - $opening - $flows['contributions_cents'] + $flows['withdrawals_cents'];

        return [
            'opening_cents' => $opening,
            'closing_cents' => $closing,
            'contributions_cents' => $flows['contributions_cents'],
            'withdrawals_cents' => $flows['withdrawals_cents'],
            'investment_movement_cents' => $investmentMovement,
            'currency' => $currency,
            'opening_as_of' => $openingAsOf,
            'used_synthetic_opening' => $usedSyntheticOpening,
        ];
    }

    /**
     * @return array{contributions_cents: int, withdrawals_cents: int}
     */
    public function periodFlows(WealthAsset $asset, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $transactions = WealthAssetTransaction::query()
            ->where('asset_id', $asset->id)
            ->whereDate('occurred_on', '>=', $periodStart->toDateString())
            ->whereDate('occurred_on', '<=', $periodEnd->toDateString())
            ->get();

        $contributions = 0;
        $withdrawals = 0;

        foreach ($transactions as $tx) {
            /** @var WealthAssetTransaction $tx */
            if ($tx->type === WealthTransactionType::Contribution) {
                $contributions += abs((int) $tx->amount_cents);
            } elseif ($tx->type === WealthTransactionType::Withdrawal) {
                $withdrawals += abs((int) $tx->amount_cents);
            } elseif ($tx->type === WealthTransactionType::Adjustment) {
                $signed = (int) $tx->amount_cents;
                if ($signed >= 0) {
                    $contributions += $signed;
                } else {
                    $withdrawals += abs($signed);
                }
            }
        }

        return [
            'contributions_cents' => $contributions,
            'withdrawals_cents' => $withdrawals,
        ];
    }

    public function money(int $cents, string $currency): Money
    {
        return Money::ofMinor($cents, $currency);
    }
}
