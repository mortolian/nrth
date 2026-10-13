<?php

namespace App\Domain\Reports\Services;

use App\Models\Team;
use App\Support\DownloadFilename;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ExpenseReportPdfService
{
    public const RENDER_TIME_LIMIT_SECONDS = 90;

    public const RENDER_MEMORY_LIMIT = '256M';

    public function __construct(
        private readonly ExpenseReportService $expenseReportService,
    ) {}

    /**
     * @param  array{start_year: int, label: string, from: string, to: string, is_current: bool}  $window
     * @param  array{view: string, breakdown: string}  $presentation
     */
    public function download(Team $team, array $window, array $presentation): Response
    {
        $from = Carbon::parse($window['from'])->startOfDay();
        $to = Carbon::parse($window['to'])->startOfDay();

        if ($presentation['view'] === 'detailed') {
            $count = $this->expenseReportService->count((int) $team->id, $from, $to);

            if ($count > ExpenseReportService::MAX_PDF_TRANSACTIONS) {
                throw ValidationException::withMessages([
                    'export' => __(
                        'Too many expense transactions for a PDF (:count). The limit is :max.',
                        [
                            'count' => number_format($count),
                            'max' => number_format(ExpenseReportService::MAX_PDF_TRANSACTIONS),
                        ]
                    ),
                ]);
            }
        }

        return $this->renderDownload($window, $this->document($team, $window, $presentation, $from, $to));
    }

    /**
     * @param  array{start_year: int, label: string, from: string, to: string, is_current: bool}  $window
     * @param  array{view: string, breakdown: string}  $presentation
     * @return array<string, mixed>
     */
    public function document(
        Team $team,
        array $window,
        array $presentation = ['view' => 'detailed', 'breakdown' => 'year'],
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array {
        $from ??= Carbon::parse($window['from'])->startOfDay();
        $to ??= Carbon::parse($window['to'])->startOfDay();
        $teamId = (int) $team->id;
        $detailed = $presentation['view'] === 'detailed';
        $monthly = $presentation['breakdown'] === 'monthly';

        return [
            'issuer' => $team->issuerForInvoicingDocuments(),
            'logoSrc' => $team->logoDataUriForPdf(),
            'currency' => $this->expenseReportService->currency($team),
            'financial_year' => $window,
            'presentation' => $presentation,
            'report' => $this->expenseReportService->summary($teamId, $from, $to),
            'months' => $monthly ? $this->expenseReportService->monthly($teamId, $from, $to) : [],
            'transaction_count' => $this->expenseReportService->count($teamId, $from, $to),
            'transactions' => $detailed ? $this->expenseReportService->transactionsForExport($teamId, $from, $to) : [],
            'generatedAt' => now(),
        ];
    }

    /**
     * @param  array{start_year: int, label: string, from: string, to: string, is_current: bool}  $window
     * @param  array<string, mixed>  $document
     */
    private function renderDownload(array $window, array $document): Response
    {
        $previousMemory = ini_get('memory_limit');
        $previousTime = (int) ini_get('max_execution_time');

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', self::RENDER_MEMORY_LIMIT);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::RENDER_TIME_LIMIT_SECONDS);
        }

        try {
            $pdf = Pdf::loadView('pdf.expense-report', $document)
                ->setPaper('a4', 'landscape')
                ->setOptions([
                    'isRemoteEnabled' => false,
                    'isPhpEnabled' => false,
                    'defaultFont' => 'DejaVu Sans',
                    'dpi' => 96,
                ]);

            $filename = DownloadFilename::sanitize(
                'expense-report-fy-'.$window['label'].'.pdf',
                'expense-report.pdf'
            );

            return $pdf->download($filename);
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'export' => __('Could not generate the expense report PDF. Try again in a moment.'),
            ]);
        } finally {
            if (function_exists('ini_set') && is_string($previousMemory) && $previousMemory !== '') {
                @ini_set('memory_limit', $previousMemory);
            }
            if (function_exists('set_time_limit') && $previousTime > 0) {
                @set_time_limit($previousTime);
            }
        }
    }
}
