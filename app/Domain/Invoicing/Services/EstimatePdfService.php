<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Models\Estimate;
use App\Support\DownloadFilename;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class EstimatePdfService
{
    public function generate(Estimate $estimate): Media
    {
        $estimate = Estimate::queryWithoutTeamScope()
            ->with('team')
            ->find($estimate->id);
        if ($estimate === null) {
            throw new \RuntimeException('Estimate not found.');
        }
        $estimate->loadClientWithoutTeamScope();

        $tmpPath = storage_path('app/tmp/estimate-'.$estimate->id.'-'.uniqid().'.pdf');
        File::ensureDirectoryExists(dirname($tmpPath));

        Pdf::loadView('pdf.estimate', ['estimate' => $estimate])
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
                'dpi' => 96,
            ])
            ->save($tmpPath);

        $media = $estimate
            ->addMedia($tmpPath)
            ->usingName('Estimate '.$estimate->number)
            ->usingFileName(DownloadFilename::sanitize($estimate->number.'.pdf', 'estimate.pdf'))
            ->toMediaCollection('estimate-pdfs');

        File::delete($tmpPath);

        return $media;
    }
}
