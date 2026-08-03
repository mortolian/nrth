<?php

namespace App\Domain\Backup\Services;

use App\Domain\Backup\Enums\InstanceBackupType;
use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class InstanceBackupTypeResolver
{
    public function __construct(
        private readonly InstanceBackupRetentionSettings $retention,
    ) {}

    /**
     * Resolve retention types for a single backup zip on the given date.
     *
     * @return list<string>
     */
    public function typesFor(?CarbonInterface $at = null, ?string $weeklyOn = null): array
    {
        $date = Carbon::parse($at ?? now())->startOfDay();
        $weeklyOn ??= $this->retention->current()['weekly_on'];

        $types = [InstanceBackupType::Daily->value];

        if ($this->isWeeklyDay($date, $weeklyOn)) {
            $types[] = InstanceBackupType::Weekly->value;
        }

        if ($date->isLastOfMonth()) {
            $types[] = InstanceBackupType::Monthly->value;
        }

        if ($date->month === 12 && $date->day === 31) {
            $types[] = InstanceBackupType::Yearly->value;
        }

        return $types;
    }

    public function isWeeklyDay(CarbonInterface $date, string $weeklyOn): bool
    {
        $map = [
            'sunday' => CarbonInterface::SUNDAY,
            'monday' => CarbonInterface::MONDAY,
            'tuesday' => CarbonInterface::TUESDAY,
            'wednesday' => CarbonInterface::WEDNESDAY,
            'thursday' => CarbonInterface::THURSDAY,
            'friday' => CarbonInterface::FRIDAY,
            'saturday' => CarbonInterface::SATURDAY,
        ];

        $target = $map[$weeklyOn] ?? CarbonInterface::SUNDAY;

        return (int) $date->dayOfWeek === $target;
    }
}
