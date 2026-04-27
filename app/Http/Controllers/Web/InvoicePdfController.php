<?php

namespace App\Http\Controllers\Web;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePdfService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
}
