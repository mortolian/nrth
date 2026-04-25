<?php

namespace App\Http\Controllers\Web;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePdfService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoicePdfController extends Controller
{
    public function __construct(
        private readonly InvoicePdfService $invoicePdfService,
    ) {}

    public function download(Invoice $invoice): StreamedResponse
    {
        abort_unless($invoice->team_id === auth()->user()->current_team_id, 403);

        $invoice->loadMissing('client', 'lineItems', 'team');
        $media = $invoice->getFirstMedia('invoice-pdfs') ?? $this->invoicePdfService->generate($invoice);

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
