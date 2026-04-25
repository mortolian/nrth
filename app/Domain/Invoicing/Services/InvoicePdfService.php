<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\File;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class InvoicePdfService
{
    public function generate(Invoice $invoice): Media
    {
        $invoice->loadMissing(['team', 'client', 'lineItems']);

        $tmpPath = storage_path('app/tmp/invoice-'.$invoice->id.'-'.uniqid().'.pdf');
        File::ensureDirectoryExists(dirname($tmpPath));

        Pdf::view('pdf.invoice', ['invoice' => $invoice])
            ->format('a4')
            ->name($invoice->number.'.pdf')
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
