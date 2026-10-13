<?php

namespace App\Domain\Reports\Services;

use App\Models\Team;
use App\Support\FinancialYear;
use App\Support\Iso4217Currencies;
use Carbon\Carbon;

final class ReportPeriod
{
    /**
     * @return array{
     *     start_year: int,
     *     label: string,
     *     from: string,
     *     to: string,
     *     is_current: bool
     * }
     */
    public static function window(Team $team, ?int $startYear): array
    {
        $startMonth = self::startMonth($team);
        [$currentStart] = FinancialYear::windowContaining(now(), $startMonth);

        $start = $currentStart->copy();
        if ($startYear !== null && $startYear >= 1970 && $startYear <= 2100) {
            $start = Carbon::create($startYear, $startMonth, 1)->startOfDay();
        }

        $end = $start->copy()->addYear()->subDay()->startOfDay();

        return [
            'start_year' => (int) $start->year,
            'label' => FinancialYear::labelForWindow($start, $end),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'is_current' => $start->equalTo($currentStart),
        ];
    }

    /**
     * @return list<array{start_year: int, label: string, from: string, to: string}>
     */
    public static function yearOptions(Team $team): array
    {
        $startMonth = self::startMonth($team);
        [$currentStart] = FinancialYear::windowContaining(now(), $startMonth);
        $options = [];

        for ($i = 0; $i < 8; $i++) {
            $start = $currentStart->copy()->subYears($i)->startOfDay();
            $end = $start->copy()->addYear()->subDay()->startOfDay();
            $options[] = [
                'start_year' => (int) $start->year,
                'label' => FinancialYear::labelForWindow($start, $end),
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ];
        }

        return $options;
    }

    /**
     * @return array{view: string, breakdown: string}
     */
    public static function presentation(?string $view, ?string $breakdown): array
    {
        return [
            'view' => $view === 'summary' ? 'summary' : 'detailed',
            'breakdown' => $breakdown === 'monthly' ? 'monthly' : 'year',
        ];
    }

    public static function currency(Team $team): string
    {
        $settings = $team->mergedBusinessSettings();

        return Iso4217Currencies::normalize(
            is_string($settings['invoice_default_currency'] ?? null)
                ? $settings['invoice_default_currency']
                : null
        );
    }

    private static function startMonth(Team $team): int
    {
        $settings = $team->mergedBusinessSettings();
        $endMonth = (int) ($settings['financial_year_end_month'] ?? 2);

        return FinancialYear::startMonthFromEndMonth($endMonth);
    }
}
