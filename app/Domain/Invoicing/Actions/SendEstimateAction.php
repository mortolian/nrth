<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\EstimateStatus;
use App\Domain\Invoicing\Models\Estimate;
use App\Domain\Invoicing\Services\EstimatePdfService;
use App\Mail\EstimateMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class SendEstimateAction
{
    public function __construct(
        private readonly EstimatePdfService $estimatePdfService,
    ) {}

    public function execute(Estimate $estimate): Estimate
    {
        if (in_array($estimate->status, [EstimateStatus::Declined, EstimateStatus::Converted], true)) {
            throw ValidationException::withMessages([
                'status' => __('Cannot send this estimate in its current state.'),
            ]);
        }

        return DB::transaction(function () use ($estimate): Estimate {
            $estimate->loadMissing(['client', 'team']);
            $pdfMedia = $this->safelyGeneratePdf($estimate);

            if ($estimate->status === EstimateStatus::Draft) {
                $estimate->status = EstimateStatus::Sent;
                $estimate->sent_at = now();
                $estimate->save();
            }

            if (! empty($estimate->client?->email)) {
                Mail::to($estimate->client->email)->queue(new EstimateMailer($estimate->fresh(), $pdfMedia));
            }

            Log::info('Estimate queued for delivery', [
                'estimate_id' => $estimate->id,
                'team_id' => $estimate->team_id,
                'client_id' => $estimate->client_id,
                'pdf_media_id' => $pdfMedia?->id,
                'pdf_attached' => $pdfMedia !== null,
            ]);

            return $estimate->refresh();
        });
    }

    private function safelyGeneratePdf(Estimate $estimate): ?Media
    {
        try {
            return $this->estimatePdfService->generate($estimate);
        } catch (Throwable $e) {
            Log::warning('Estimate PDF generation failed; sending without attachment', [
                'estimate_id' => $estimate->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
