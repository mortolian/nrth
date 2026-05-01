<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Support\InvoiceOnlinePaymentProviders;
use App\Support\InvoicePayQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class InvoicePdfService
{
    public function generate(Invoice $invoice): Media
    {
        $tmpPath = $this->renderToTemporaryPath($invoice);
        $invoice = $invoice->fresh();
        if ($invoice === null) {
            File::delete($tmpPath);
            throw new \RuntimeException('Invoice not found.');
        }

        try {
            return $invoice
                ->addMedia($tmpPath)
                ->usingName('Invoice '.$invoice->number)
                ->usingFileName($invoice->number.'.pdf')
                ->toMediaCollection('invoice-pdfs');
        } finally {
            File::delete($tmpPath);
        }
    }

    /**
     * Render the invoice PDF to a temp path. Caller must delete the file when done.
     */
    public function renderToTemporaryPath(Invoice $invoice): string
    {
        $invoice = $invoice->fresh(['team', 'client', 'lineItems']);
        if ($invoice === null) {
            throw new \RuntimeException('Invoice not found.');
        }

        $tmpPath = storage_path('app/tmp/invoice-'.$invoice->id.'-'.uniqid().'.pdf');
        File::ensureDirectoryExists(dirname($tmpPath));

        $publicPayUrl = null;
        $publicPayQrDataUri = null;
        if (
            $invoice->public_token !== null
            && ! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Void], true)
            && InvoiceOnlinePaymentProviders::paymentPagesEnabledForTeam($invoice->team)
        ) {
            $publicPayUrl = route('public.invoice.pay', ['token' => $invoice->public_token], true);
            $publicPayQrDataUri = InvoicePayQrCode::pngDataUri($publicPayUrl, 168, 8);
        }

        Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'public_pay_url' => $publicPayUrl,
            'public_pay_qr_data_uri' => $publicPayQrDataUri,
        ])
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => true,
                'isPhpEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
                'dpi' => 96,
            ])
            ->save($tmpPath);

        return $tmpPath;
    }
}
