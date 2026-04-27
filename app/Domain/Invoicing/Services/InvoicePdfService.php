<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class InvoicePdfService
{
    public function generate(Invoice $invoice): Media
    {
        $invoice = $invoice->fresh(['team', 'client', 'lineItems']);
        if ($invoice === null) {
            throw new \RuntimeException('Invoice not found.');
        }

        $tmpPath = storage_path('app/tmp/invoice-'.$invoice->id.'-'.uniqid().'.pdf');
        File::ensureDirectoryExists(dirname($tmpPath));

        Pdf::loadView('pdf.invoice', ['invoice' => $invoice])
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => true,
                'isPhpEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
                'dpi' => 96,
            ])
            ->save($tmpPath);

        $media = $invoice
            ->addMedia($tmpPath)
            ->usingName('Invoice '.$invoice->number)
            ->usingFileName($invoice->number.'.pdf')
            ->toMediaCollection('invoice-pdfs');

        File::delete($tmpPath);

        return $media;
    }
}
