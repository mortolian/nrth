<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Models\Estimate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class EstimatePdfService
{
    public function generate(Estimate $estimate): Media
    {
        $estimate = $estimate->fresh(['team', 'client']);
        if ($estimate === null) {
            throw new \RuntimeException('Estimate not found.');
        }

        $tmpPath = storage_path('app/tmp/estimate-'.$estimate->id.'-'.uniqid().'.pdf');
        File::ensureDirectoryExists(dirname($tmpPath));

        Pdf::loadView('pdf.estimate', ['estimate' => $estimate])
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => true,
                'isPhpEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
                'dpi' => 96,
            ])
            ->save($tmpPath);

        $media = $estimate
            ->addMedia($tmpPath)
            ->usingName('Estimate '.$estimate->number)
            ->usingFileName($estimate->number.'.pdf')
            ->toMediaCollection('estimate-pdfs');

        File::delete($tmpPath);

        return $media;
    }
}
