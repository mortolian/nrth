<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\QuoteStatus;
use App\Domain\Invoicing\Models\Quote;
use App\Domain\Invoicing\Services\QuotePdfService;
use App\Mail\QuoteMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class SendQuoteAction
{
    public function __construct(
        private readonly QuotePdfService $quotePdfService,
    ) {}

    public function execute(Quote $quote): Quote
    {
        if (in_array($quote->status, [QuoteStatus::Declined, QuoteStatus::Converted], true)) {
            throw ValidationException::withMessages([
                'status' => __('Cannot send this quote in its current state.'),
            ]);
        }

        return DB::transaction(function () use ($quote): Quote {
            $quote->loadMissing(['client', 'team']);
            $pdfMedia = $this->safelyGeneratePdf($quote);

            if ($quote->status === QuoteStatus::Draft) {
                $quote->status = QuoteStatus::Sent;
                $quote->sent_at = now();
                $quote->save();
            }

            if (! empty($quote->client?->email)) {
                Mail::to($quote->client->email)->queue(new QuoteMailer($quote->fresh(), $pdfMedia));
            }

            Log::info('Quote queued for delivery', [
                'quote_id' => $quote->id,
                'team_id' => $quote->team_id,
                'client_id' => $quote->client_id,
                'pdf_media_id' => $pdfMedia?->id,
                'pdf_attached' => $pdfMedia !== null,
            ]);

            return $quote->refresh();
        });
    }

    private function safelyGeneratePdf(Quote $quote): ?Media
    {
        try {
            return $this->quotePdfService->generate($quote);
        } catch (Throwable $e) {
            Log::warning('Quote PDF generation failed; sending without attachment', [
                'quote_id' => $quote->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

