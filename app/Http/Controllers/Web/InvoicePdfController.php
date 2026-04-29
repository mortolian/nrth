<?php

namespace App\Http\Controllers\Web;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePdfService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class InvoicePdfController extends Controller
{
    public function __construct(
        private readonly InvoicePdfService $invoicePdfService,
    ) {}

    public function download(Invoice $invoice): StreamedResponse|RedirectResponse
    {
        abort_unless($invoice->team_id === auth()->user()->current_team_id, 403);

        try {
            $media = $this->invoicePdfService->generate($invoice);
        } catch (Throwable $e) {
            Log::warning('Invoice PDF download failed; missing PDF generator', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'The invoice PDF could not be generated. Please try again or contact support.');
        }

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();
        $stream = $disk->readStream($path);

        return response()->streamDownload(function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $media->file_name, [
            'Content-Type' => $media->mime_type ?: 'application/pdf',
        ]);
    }

    public function downloadZip(Request $request): StreamedResponse|RedirectResponse|JsonResponse
    {
        $teamId = auth()->user()->current_team_id;
        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['integer', 'distinct'],
        ]);

        $invoices = Invoice::query()
            ->where('team_id', $teamId)
            ->whereIn('id', $validated['invoice_ids'])
            ->orderBy('id')
            ->get();

        if ($invoices->count() !== count(array_unique($validated['invoice_ids']))) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['message' => 'One or more invoices could not be found.'], 422);
            }

            return redirect()
                ->back()
                ->with('error', 'One or more invoices could not be found.');
        }

        $zipPath = storage_path('app/tmp/invoices-bulk-'.uniqid('', true).'.zip');
        $zipDir = dirname($zipPath);
        if (! is_dir($zipDir)) {
            mkdir($zipDir, 0755, true);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['message' => 'Could not create the export archive.'], 422);
            }

            return redirect()
                ->back()
                ->with('error', 'Could not create the export archive.');
        }

        $tempPdfs = [];
        try {
            foreach ($invoices as $invoice) {
                try {
                    $pdfPath = $this->invoicePdfService->renderToTemporaryPath($invoice);
                } catch (Throwable $e) {
                    Log::warning('Invoice PDF zip: single invoice failed', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                    $zip->close();
                    @unlink($zipPath);
                    foreach ($tempPdfs as $p) {
                        @unlink($p);
                    }

                    if ($request->ajax() || $request->wantsJson()) {
                        return response()->json(['message' => 'The invoice PDFs could not be generated. Please try again or contact support.'], 422);
                    }

                    return redirect()
                        ->back()
                        ->with('error', 'The invoice PDFs could not be generated. Please try again or contact support.');
                }

                $tempPdfs[] = $pdfPath;
                $safeNumber = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $invoice->number) ?: 'invoice';
                $zip->addFile($pdfPath, $invoice->id.'-'.$safeNumber.'.pdf');
            }

            $zip->close();
        } catch (Throwable $e) {
            $zip->close();
            @unlink($zipPath);
            foreach ($tempPdfs as $p) {
                @unlink($p);
            }
            throw $e;
        }

        foreach ($tempPdfs as $p) {
            @unlink($p);
        }

        $downloadName = 'invoices-'.now()->format('Y-m-d-His').'.zip';

        return response()->streamDownload(function () use ($zipPath): void {
            readfile($zipPath);
            @unlink($zipPath);
        }, $downloadName, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
