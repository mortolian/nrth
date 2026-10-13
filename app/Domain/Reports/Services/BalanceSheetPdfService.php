<?php

namespace App\Domain\Reports\Services;

use App\Models\Team;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\Response;

final class BalanceSheetPdfService
{
    public function __construct(
        private readonly BalanceSheetReportService $balanceSheetReportService,
    ) {}

    public function download(Team $team, CarbonInterface $asOf): Response
    {
        return ReportPdf::download(
            'pdf.balance-sheet',
            $this->document($team, $asOf),
            'balance-sheet-'.$asOf->toDateString().'.pdf',
            'portrait',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function document(Team $team, CarbonInterface $asOf): array
    {
        return [
            ...ReportPdf::chrome($team),
            'currency' => ReportPeriod::currency($team),
            'as_of' => $asOf->toDateString(),
            'report' => $this->balanceSheetReportService->asOf($team, $asOf->copy()),
        ];
    }
}
