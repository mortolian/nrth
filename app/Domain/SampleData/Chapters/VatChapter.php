<?php

namespace App\Domain\SampleData\Chapters;

use App\Domain\SampleData\SampleChapter;
use App\Domain\SampleData\SampleContext;
use App\Domain\Tax\Actions\GenerateVATReturnAction;
use App\Domain\Tax\Enums\TaxPeriodStatus;
use App\Domain\Tax\Enums\TaxPeriodType;
use App\Domain\Tax\Models\TaxPeriod;

final class VatChapter implements SampleChapter
{
    public function key(): string
    {
        return 'vat';
    }

    public function seed(SampleContext $context): void
    {
        $generate = app(GenerateVATReturnAction::class);
        $cursor = $context->calendar->start->copy();

        while ($cursor->lessThanOrEqualTo($context->calendar->end)) {
            $periodEnd = $cursor->copy()->addMonths(2)->subDay();
            if ($periodEnd->greaterThan($context->calendar->end)) {
                $periodEnd = $context->calendar->end->copy();
            }

            if ($cursor->greaterThan($context->calendar->asOf)) {
                break;
            }

            $complete = $periodEnd->lessThan($context->calendar->asOf);
            $period = TaxPeriod::query()->create([
                'team_id' => $context->team->id,
                'period_start' => $cursor->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'type' => TaxPeriodType::VAT,
                'status' => TaxPeriodStatus::Open,
                'due_date' => $periodEnd->copy()->addDays(25)->toDateString(),
            ]);

            if ($complete) {
                $generate->execute($period);
            }

            $cursor = $periodEnd->copy()->addDay();
        }
    }
}
