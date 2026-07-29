<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\Services\PaymentReceiptPdfService;
use App\Mail\PaymentReceiptMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class SendPaymentReceiptAction
{
    public function __construct(
        private readonly PaymentReceiptPdfService $paymentReceiptPdfService,
    ) {}

    public function execute(Payment $payment): Payment
    {
        $payment->loadMissing(['invoice.client', 'team']);

        $email = trim((string) ($payment->invoice?->client?->email ?? ''));
        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => __('Add an email address on the client before sending this receipt.'),
            ]);
        }

        $pdfMedia = $this->safelyGeneratePdf($payment);

        return DB::transaction(function () use ($payment, $pdfMedia, $email): Payment {
            $fresh = $payment->fresh(['invoice.client', 'team']);

            DB::afterCommit(function () use ($fresh, $pdfMedia, $email): void {
                Mail::to($email)->queue(new PaymentReceiptMailer($fresh, $pdfMedia?->id));
            });

            if (function_exists('activity') && $payment->invoice !== null) {
                activity()
                    ->performedOn($payment->invoice)
                    ->withProperties([
                        'payment_id' => $payment->id,
                        'pdf_media_id' => $pdfMedia?->id,
                    ])
                    ->log('payment_receipt_sent');
            }

            Log::info('Payment receipt queued for delivery', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'team_id' => $payment->team_id,
                'client_email' => $email,
                'pdf_media_id' => $pdfMedia?->id,
                'pdf_attached' => $pdfMedia !== null,
            ]);

            return $payment->refresh();
        });
    }

    private function safelyGeneratePdf(Payment $payment): ?Media
    {
        try {
            return $this->paymentReceiptPdfService->generate($payment);
        } catch (Throwable $e) {
            Log::warning('Payment receipt PDF generation failed; sending without attachment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
