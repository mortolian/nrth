<?php

namespace App\Domain\Tax\Actions;

use App\Domain\Tax\Enums\TaxPeriodStatus;
use App\Domain\Tax\Enums\TaxPeriodType;
use App\Domain\Tax\Models\TaxPeriod;
use App\Domain\Tax\Services\ProvisionalTaxService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CreateTaxPeriodAction
{
    public function __construct(
        private readonly ProvisionalTaxService $provisionalTaxService,
    ) {}

    /**
     * @return array{vat: list<TaxPeriod>, provisional: list<TaxPeriod>}
     */
    public function execute(int $teamId, int $year): array
    {
        return DB::transaction(function () use ($teamId, $year): array {
            $vatPeriods = [];

            // Default bi-monthly VAT periods in calendar year.
            for ($month = 1; $month <= 12; $month += 2) {
                $periodStart = Carbon::create($year, $month, 1)->startOfDay();
                $periodEnd = $periodStart->copy()->addMonthsNoOverflow(2)->subDay();

                $vatPeriods[] = TaxPeriod::queryWithoutTeamScope()->firstOrCreate(
                    [
                        'team_id' => $teamId,
                        'type' => TaxPeriodType::VAT,
                        'period_start' => $periodStart->toDateString(),
                        'period_end' => $periodEnd->toDateString(),
                    ],
                    [
                        'status' => TaxPeriodStatus::Open,
                        'due_date' => $periodEnd->copy()->addDays(25)->toDateString(),
                    ]
                );
            }

            $provisionalPeriods = [];
            foreach ($this->provisionalTaxService->getProvisionalPeriods($year) as $window) {
                $provisionalPeriods[] = TaxPeriod::queryWithoutTeamScope()->firstOrCreate(
                    [
                        'team_id' => $teamId,
                        'type' => TaxPeriodType::Provisional,
                        'period_start' => $window['start']->toDateString(),
                        'period_end' => $window['end']->toDateString(),
                    ],
                    [
                        'status' => TaxPeriodStatus::Open,
                        'due_date' => $window['end']->addMonth()->toDateString(),
                    ]
                );
            }

            return [
                'vat' => $vatPeriods,
                'provisional' => $provisionalPeriods,
            ];
        });
    }
}
