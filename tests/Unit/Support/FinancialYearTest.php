<?php

namespace Tests\Unit\Support;

use App\Support\FinancialYear;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialYearTest extends TestCase
{
    public function test_start_month_from_end_month(): void
    {
        $this->assertSame(3, FinancialYear::startMonthFromEndMonth(2));
        $this->assertSame(1, FinancialYear::startMonthFromEndMonth(12));
    }

    #[DataProvider('windowProvider')]
    public function test_window_containing(string $date, int $startMonth, string $expectedStart, string $expectedEnd, string $label): void
    {
        [$start, $end] = FinancialYear::windowContaining(Carbon::parse($date), $startMonth);

        $this->assertSame($expectedStart, $start->toDateString());
        $this->assertSame($expectedEnd, $end->toDateString());
        $this->assertSame($label, FinancialYear::labelForWindow($start, $end));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: string, 4: string}>
     */
    public static function windowProvider(): array
    {
        return [
            'aug in mar-feb fy' => ['2026-08-17', 3, '2026-03-01', '2027-02-28', '2026/27'],
            'jan in prior mar-feb fy' => ['2026-01-15', 3, '2025-03-01', '2026-02-28', '2025/26'],
            'calendar year fy' => ['2026-06-01', 1, '2026-01-01', '2026-12-31', '2026'],
        ];
    }
}
