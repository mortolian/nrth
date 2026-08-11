<?php

namespace App\Modules\Wealth\Calculators;

use App\Modules\Wealth\Enums\WealthTransactionType;
use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthAssetTransaction;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class InvestmentMovementCalculator
{
    /**
     * @return array{
     *     opening_cents: int,
     *     closing_cents: int,
     *     contributions_cents: int,
     *     withdrawals_cents: int,
     *     investment_movement_cents: int,
     *     currency: string
     * }
     */
    public function forAsset(WealthAsset $asset, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $currency = $asset->currency;
        $openingDate = $periodStart->copy()->subDay();

        $opening = $asset->valueCentsAsOf($openingDate);
        $closing = $asset->valueCentsAsOf($periodEnd);

        $flows = $this->periodFlows($asset, $periodStart, $periodEnd);

        $investmentMovement = $closing - $opening - $flows['contributions_cents'] + $flows['withdrawals_cents'];

        return [
            'opening_cents' => $opening,
            'closing_cents' => $closing,
            'contributions_cents' => $flows['contributions_cents'],
            'withdrawals_cents' => $flows['withdrawals_cents'],
            'investment_movement_cents' => $investmentMovement,
            'currency' => $currency,
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
