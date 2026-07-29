<?php

namespace Tests\Unit\Support;

use App\Support\CalendarMonths;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CalendarMonthsTest extends TestCase
{
    #[Test]
    public function it_includes_february_even_when_today_is_the_29th_of_a_non_leap_year(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 29, 12, 0, 0));

        $options = CalendarMonths::options();

        $this->assertCount(12, $options);
        $this->assertSame(
            ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            array_column($options, 'label'),
        );
        $this->assertSame(range(1, 12), array_column($options, 'value'));

        Carbon::setTestNow();
    }
}
