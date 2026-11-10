<?php

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Models\StatementFilterReport;
use App\Models\Team;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\Response;

final class StatementFilterReportPdfService
{
    public function __construct(
        private readonly StatementFilterReportService $statementFilterReportService,
    ) {}

    public function download(
        Team $team,
        StatementFilterReport $report,
        CarbonInterface $from,
        CarbonInterface $to,
        string $breakdown,
        string $view,
    ): Response {
        $result = $this->statementFilterReportService->run($report, $from, $to, $breakdown, $view);

        return ReportPdf::download(
            'pdf.statement-filter-report',
            [
                ...ReportPdf::chrome($team),
                'currency' => ReportPeriod::currency($team),
                'reportName' => $report->name,
                ...$result,
            ],
            'statement-filters-'.$report->id.'-'.$from->toDateString().'.pdf',
            'portrait',
        );
    }
}
