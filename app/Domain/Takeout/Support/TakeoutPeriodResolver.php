<?php

namespace App\Domain\Takeout\Support;

use Carbon\Carbon;

final class TakeoutPeriodResolver
{
    /**
     * @return array{from: string, to: string, preset: string}
     */
    public function resolve(?string $preset, ?string $from, ?string $to): array
    {
        $preset = $preset ?: 'this_tax_year';

        if ($preset === 'previous_tax_year') {
            [$start, $end] = $this->taxYearWindow(now(), true);

            return [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'preset' => $preset,
            ];
        }

        if ($preset === 'this_tax_year') {
            [$start, $end] = $this->taxYearWindow(now(), false);

            return [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'preset' => $preset,
            ];
        }

        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->startOfYear();
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now()->endOfYear();
        if ($toDate->lt($fromDate)) {
            $toDate = $fromDate->copy();
        }

        return [
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'preset' => 'custom',
        ];
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private function taxYearWindow(Carbon $base, bool $previous): array
    {
        $start = $base->month >= 3
            ? Carbon::create($base->year, 3, 1)->startOfDay()
            : Carbon::create($base->year - 1, 3, 1)->startOfDay();
        if ($previous) {
            $start = $start->copy()->subYear();
        }
        $end = $start->copy()->addYear()->subDay()->endOfDay();

        return [$start, $end];
    }
}
