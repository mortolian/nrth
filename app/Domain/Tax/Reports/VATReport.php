<?php

namespace App\Domain\Tax\Reports;

use App\Domain\Tax\DTOs\VATSummaryDTO;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VATReport
{
    public function __construct(
        private readonly VATSummaryDTO $summary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period_start' => $this->summary->periodStart->toDateString(),
            'period_end' => $this->summary->periodEnd->toDateString(),
            'output_vat_cents' => $this->summary->outputVAT->getMinorAmount()->toInt(),
            'input_vat_cents' => $this->summary->inputVAT->getMinorAmount()->toInt(),
            'net_vat_cents' => $this->summary->netVAT->getMinorAmount()->toInt(),
            // SARS VAT201-style headline boxes.
            'box_1_standard_rated_supplies' => $this->summary->outputVAT->getMinorAmount()->toInt(),
            'box_14_vat_payable_refundable' => $this->summary->netVAT->getMinorAmount()->toInt(),
        ];
    }

    public function toExcel(string $filename = 'vat-return.xlsx'): BinaryFileResponse
    {
        $rows = [
            ['Field', 'Value'],
        ];

        foreach ($this->toArray() as $key => $value) {
            $rows[] = [$key, $value];
        }

        return Excel::download(new class($rows) implements FromArray
        {
            /**
             * @param  array<int, array<int, string|int>>  $rows
             */
            public function __construct(private readonly array $rows) {}

            public function array(): array
            {
                return $this->rows;
            }
        }, $filename);
    }
}
