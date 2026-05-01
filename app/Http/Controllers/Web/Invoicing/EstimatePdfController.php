<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Invoicing\Models\Estimate;
use App\Domain\Invoicing\Services\EstimatePdfService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EstimatePdfController extends Controller
{
    public function __construct(
        private readonly EstimatePdfService $estimatePdfService,
    ) {}

    public function download(Estimate $estimate): StreamedResponse|RedirectResponse
    {
        abort_unless($estimate->team_id === auth()->user()->current_team_id, 403);

        try {
            $media = $this->estimatePdfService->generate($estimate);
        } catch (Throwable $e) {
            Log::warning('Estimate PDF download failed; missing PDF generator', [
                'estimate_id' => $estimate->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'The estimate PDF could not be generated. Please try again or contact support.');
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
