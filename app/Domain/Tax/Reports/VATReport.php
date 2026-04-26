<?php

namespace App\Domain\Tax\Reports;

use App\Domain\Tax\DTOs\VATSummaryDTO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function toExcel(string $filename = 'vat-return.xlsx'): StreamedResponse
    {
        $rows = [
            ['Field', 'Value'],
        ];

        foreach ($this->toArray() as $key => $value) {
            $rows[] = [$key, $value];
        }

        return new StreamedResponse(function () use ($rows): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $rowIndex = 1;
            foreach ($rows as $row) {
                $colIndex = 1;
                foreach ($row as $cell) {
                    $sheet->setCellValue([$colIndex, $rowIndex], $cell);
                    $colIndex++;
                }
                $rowIndex++;
            }
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
