<?php

namespace App\Domain\Reports\Services;

use App\Domain\Invoicing\Models\Client;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ClientStatementPdfService
{
    public function __construct(
        private readonly ClientStatementService $clientStatementService,
    ) {}

    public function download(Team $team, Client $client, CarbonInterface $from, CarbonInterface $to): Response
    {
        $document = $this->document($team, $client, $from, $to);
        $lineCount = count($document['statement']['lines'] ?? []);

        if ($lineCount > ClientStatementService::MAX_PDF_LINES) {
            throw ValidationException::withMessages([
                'export' => __(
                    'Too many statement lines for a PDF (:count). The limit is :max.',
                    [
                        'count' => number_format($lineCount),
                        'max' => number_format(ClientStatementService::MAX_PDF_LINES),
                    ]
                ),
            ]);
        }

        $slug = Str::slug((string) $client->name) ?: 'client';

        return ReportPdf::download(
            'pdf.client-statement',
            $document,
            'client-statement-'.$slug.'-'.$document['statement']['period']['from'].'.pdf',
            'portrait',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function document(Team $team, Client $client, CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            ...ReportPdf::chrome($team),
            'currency' => $this->clientStatementService->currency($team),
            'statement' => $this->clientStatementService->statement((int) $team->id, $client, $from, $to),
        ];
    }
}
