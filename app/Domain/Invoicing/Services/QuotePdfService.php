<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class QuotePdfService
{
    public function generate(Quote $quote): Media
    {
        $quote->loadMissing(['team', 'client']);

        $tmpPath = storage_path('app/tmp/quote-'.$quote->id.'-'.uniqid().'.pdf');
        File::ensureDirectoryExists(dirname($tmpPath));

        Pdf::loadView('pdf.quote', ['quote' => $quote])
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => true,
                'isPhpEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
                'dpi' => 96,
            ])
            ->save($tmpPath);

        $media = $quote
            ->addMedia($tmpPath)
            ->usingName('Quote '.$quote->number)
            ->usingFileName($quote->number.'.pdf')
            ->toMediaCollection('quote-pdfs');

        File::delete($tmpPath);

        return $media;
    }
}
