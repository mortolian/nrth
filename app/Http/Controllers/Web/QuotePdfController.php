<?php

namespace App\Http\Controllers\Web;

use App\Domain\Invoicing\Models\Quote;
use App\Domain\Invoicing\Services\QuotePdfService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class QuotePdfController extends Controller
{
    public function __construct(
        private readonly QuotePdfService $quotePdfService,
    ) {}

    public function download(Quote $quote): StreamedResponse|RedirectResponse
    {
        abort_unless($quote->team_id === auth()->user()->current_team_id, 403);

        try {
            $media = $this->quotePdfService->generate($quote);
        } catch (Throwable $e) {
            Log::warning('Quote PDF download failed; missing PDF generator', [
                'quote_id' => $quote->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'The quote PDF could not be generated. Please try again or contact support.');
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

