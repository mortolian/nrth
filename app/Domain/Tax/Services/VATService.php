<?php

namespace App\Domain\Tax\Services;

use App\Domain\Accounting\Enums\TaxLineType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tax\DTOs\VATSummaryDTO;
use App\Domain\Tax\Models\TaxPeriod;
use App\Models\Team;
use Brick\Money\Money;
use Carbon\CarbonInterface;

class VATService
{
    public function calculateOutputVAT(Team $team, CarbonInterface $from, CarbonInterface $to): Money
    {
        $cents = (int) Invoice::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', [InvoiceStatus::Void->value])
            ->sum('vat_amount_cents');

        return Money::ofMinor($cents, 'ZAR');
    }

    public function calculateInputVAT(Team $team, CarbonInterface $from, CarbonInterface $to): Money
    {
        $cents = (int) Transaction::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('status', TransactionStatus::Posted)
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('taxLines', fn ($q) => $q->where('type', TaxLineType::Input->value))
            ->with(['taxLines'])
            ->get()
            ->flatMap->taxLines
            ->where('type', TaxLineType::Input)
            ->sum(fn ($line) => (int) $line->getRawOriginal('tax_amount_cents'));

        return Money::ofMinor($cents, 'ZAR');
    }

    public function calculateNetVAT(Team $team, CarbonInterface $from, CarbonInterface $to): Money
    {
        $output = $this->calculateOutputVAT($team, $from, $to);
        $input = $this->calculateInputVAT($team, $from, $to);

        return $output->minus($input);
    }

    public function getVATSummary(Team $team, TaxPeriod $period): VATSummaryDTO
    {
        $output = $this->calculateOutputVAT($team, $period->period_start, $period->period_end);
        $input = $this->calculateInputVAT($team, $period->period_start, $period->period_end);

        return new VATSummaryDTO(
            outputVAT: $output,
            inputVAT: $input,
            netVAT: $output->minus($input),
            periodStart: $period->period_start,
            periodEnd: $period->period_end,
        );
    }
}
