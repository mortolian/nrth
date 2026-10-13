<?php

namespace App\Domain\Reports\Services;

use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class AgedReceivablesPdfService
{
    public function __construct(
        private readonly AgedReceivablesService $agedReceivablesService,
    ) {}

    public function download(Team $team, CarbonInterface $asOf): Response
    {
        $document = $this->document($team, $asOf);

        if ((int) $document['report']['invoice_count'] > AgedReceivablesService::MAX_PDF_INVOICES) {
            throw ValidationException::withMessages([
                'export' => __(
                    'Too many open invoices for a PDF (:count). The limit is :max.',
                    [
                        'count' => number_format((int) $document['report']['invoice_count']),
                        'max' => number_format(AgedReceivablesService::MAX_PDF_INVOICES),
                    ]
                ),
            ]);
        }

        return ReportPdf::download(
            'pdf.aged-receivables',
            $document,
            'aged-receivables-'.$asOf->toDateString().'.pdf',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function document(Team $team, CarbonInterface $asOf): array
    {
        return [
            ...ReportPdf::chrome($team),
            'currency' => $this->agedReceivablesService->currency($team),
            'report' => $this->agedReceivablesService->asOf((int) $team->id, $asOf),
        ];
    }
}
