<?php

namespace App\Support;

use Carbon\Carbon;

final class CalendarMonths
{
    /**
     * Month options for selects (1–12). Uses day 1 so February is never skipped
     * when “today” is the 29th–31st.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array
    {
        return collect(range(1, 12))
            ->map(fn (int $month): array => [
                'value' => $month,
                'label' => Carbon::create(2001, $month, 1)->format('F'),
            ])
            ->all();
    }
}
