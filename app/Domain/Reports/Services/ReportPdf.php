<?php

namespace App\Domain\Reports\Services;

use App\Models\Team;
use App\Support\DownloadFilename;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ReportPdf
{
    public const RENDER_TIME_LIMIT_SECONDS = 90;

    public const RENDER_MEMORY_LIMIT = '256M';

    /**
     * @return array{issuer: array<string, mixed>, logoSrc: ?string, generatedAt: CarbonInterface}
     */
    public static function chrome(Team $team): array
    {
        return [
            'issuer' => $team->issuerForInvoicingDocuments(),
            'logoSrc' => $team->logoDataUriForPdf(),
            'generatedAt' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function download(string $view, array $data, string $filename, string $orientation = 'landscape'): Response
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
            $pdf = Pdf::loadView($view, $data)
                ->setPaper('a4', $orientation)
                ->setOptions([
                    'isRemoteEnabled' => false,
                    'isPhpEnabled' => false,
                    'defaultFont' => 'DejaVu Sans',
                    'dpi' => 96,
                ]);

            return $pdf->download(DownloadFilename::sanitize($filename, 'report.pdf'));
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'export' => __('Could not generate the report PDF. Try again in a moment.'),
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
