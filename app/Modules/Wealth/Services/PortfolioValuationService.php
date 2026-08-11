<?php

namespace App\Modules\Wealth\Services;

use App\Modules\Wealth\Models\WealthAsset;
use App\Modules\Wealth\Models\WealthPortfolio;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class PortfolioValuationService
{
    /**
     * @param  Collection<int, WealthAsset>|null  $assets
     */
    public function valueAsOf(WealthPortfolio $portfolio, CarbonInterface $asOf, ?Collection $assets = null): int
    {
        $assets ??= $portfolio->assets()->where('is_active', true)->get();

        $total = 0;
        foreach ($assets as $asset) {
            if ($asset->currency !== $portfolio->base_currency) {
                continue;
            }
            $total += $asset->valueCentsAsOf($asOf);
        }

        return $total;
    }

    /**
     * Month-end (or as-of if current month) series.
     *
     * @return list<array{label: string, date: string, value_cents: int}>
     */
    public function monthlySeries(WealthPortfolio $portfolio, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $assets = $portfolio->assets()->where('is_active', true)->get();
        $to = ($to ?? now())->copy()->startOfDay();
        $from = $from?->copy()->startOfDay();

        if ($from === null) {
            $earliest = null;
            foreach ($assets as $asset) {
                $first = $asset->valuations()->reorder()->orderBy('valued_on')->value('valued_on');
                if ($first !== null) {
                    $cursor = Carbon::parse($first)->startOfMonth();
                    if ($earliest === null || $cursor->lt($earliest)) {
                        $earliest = $cursor;
                    }
                }
            }
            $from = $earliest ?? $to->copy()->startOfMonth();
        } else {
            $from = $from->copy()->startOfMonth();
        }

        $series = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $asOf = $cursor->copy()->endOfMonth()->startOfDay();
            if ($asOf->gt($to)) {
                $asOf = $to->copy();
            }
            $series[] = [
                'label' => $cursor->format('M Y'),
                'date' => $asOf->toDateString(),
                'value_cents' => $this->valueAsOf($portfolio, $asOf, $assets),
            ];
            $cursor->addMonth()->startOfMonth();
        }

        return $series;
    }

    /**
     * Value at each financial-year end (and current FY as-of today if incomplete).
     *
     * @return list<array{label: string, date: string, value_cents: int}>
     */
    public function annualSeries(WealthPortfolio $portfolio, ?CarbonInterface $to = null): array
    {
        $assets = $portfolio->assets()->where('is_active', true)->get();
        $to = ($to ?? now())->copy()->startOfDay();
        $startMonth = (int) $portfolio->financial_year_start_month;

        $earliest = null;
        foreach ($assets as $asset) {
            $first = $asset->valuations()->reorder()->orderBy('valued_on')->value('valued_on');
            if ($first !== null) {
                $d = Carbon::parse($first);
                if ($earliest === null || $d->lt($earliest)) {
                    $earliest = $d;
                }
            }
        }

        if ($earliest === null) {
            return [];
        }

        [$firstStart] = WealthFinancialYear::windowContaining($earliest, $startMonth);
        $series = [];
        $fyStart = $firstStart->copy();

        while ($fyStart->lte($to)) {
            [, $fyEnd] = WealthFinancialYear::windowContaining($fyStart, $startMonth);
            $asOf = $fyEnd->gt($to) ? $to->copy() : $fyEnd->copy();
            $series[] = [
                'label' => WealthFinancialYear::labelForWindow($fyStart, $fyEnd),
                'date' => $asOf->toDateString(),
                'value_cents' => $this->valueAsOf($portfolio, $asOf, $assets),
            ];
            $fyStart = $fyStart->copy()->addYear();
        }

        return $series;
    }
}
