<?php

namespace Tests\Unit\Backup;

use App\Domain\Backup\Services\InstanceBackupTypeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InstanceBackupTypeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekday_resolves_daily_only(): void
    {
        $resolver = app(InstanceBackupTypeResolver::class);

        // 2026-08-03 is a Monday.
        $types = $resolver->typesFor(Carbon::parse('2026-08-03'), 'sunday');

        $this->assertSame(['daily'], $types);
    }

    public function test_configured_weekly_day_adds_weekly(): void
    {
        $resolver = app(InstanceBackupTypeResolver::class);

        // 2026-08-02 is a Sunday.
        $types = $resolver->typesFor(Carbon::parse('2026-08-02'), 'sunday');

        $this->assertSame(['daily', 'weekly'], $types);
    }

    public function test_month_end_adds_monthly(): void
    {
        $resolver = app(InstanceBackupTypeResolver::class);

        // 2026-07-31 is a Friday.
        $types = $resolver->typesFor(Carbon::parse('2026-07-31'), 'sunday');

        $this->assertSame(['daily', 'monthly'], $types);
    }

    public function test_sunday_month_end_is_multi_type(): void
    {
        $resolver = app(InstanceBackupTypeResolver::class);

        // 2023-12-31 is a Sunday and year-end.
        $types = $resolver->typesFor(Carbon::parse('2023-12-31'), 'sunday');

        $this->assertSame(['daily', 'weekly', 'monthly', 'yearly'], $types);
    }
}
