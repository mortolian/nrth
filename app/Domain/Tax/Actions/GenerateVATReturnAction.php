<?php

namespace App\Domain\Tax\Actions;

use App\Domain\Tax\Enums\TaxPeriodStatus;
use App\Domain\Tax\Enums\TaxPeriodType;
use App\Domain\Tax\Models\TaxPeriod;
use App\Domain\Tax\Models\VATReturn;
use App\Domain\Tax\Services\VATService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateVATReturnAction
{
    public function __construct(
        private readonly VATService $vatService,
    ) {}

    public function execute(TaxPeriod $period): VATReturn
    {
        if ($period->type !== TaxPeriodType::VAT) {
            throw ValidationException::withMessages([
                'type' => __('VAT return can only be generated for VAT tax periods.'),
            ]);
        }

        return DB::transaction(function () use ($period): VATReturn {
            $summary = $this->vatService->getVATSummary($period->team, $period);

            $return = VATReturn::queryWithoutTeamScope()->updateOrCreate(
                ['tax_period_id' => $period->id],
                [
                    'team_id' => $period->team_id,
                    'output_vat_cents' => $summary->outputVAT->getMinorAmount()->toInt(),
                    'input_vat_cents' => $summary->inputVAT->getMinorAmount()->toInt(),
                    'net_vat_cents' => $summary->netVAT->getMinorAmount()->toInt(),
                    'period_start' => $period->period_start->toDateString(),
                    'period_end' => $period->period_end->toDateString(),
                ],
            );

            $period->status = TaxPeriodStatus::Submitted;
            $period->submitted_at = now();
            $period->save();

            return $return->refresh();
        });
    }
}
