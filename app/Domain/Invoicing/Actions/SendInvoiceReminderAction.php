<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePdfService;
use App\Mail\InvoiceReminderMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class SendInvoiceReminderAction
{
    public function __construct(
        private readonly InvoicePdfService $invoicePdfService,
    ) {}

    public function execute(Invoice $invoice): Invoice
    {
        if (! in_array($invoice->status, [
            InvoiceStatus::Sent,
            InvoiceStatus::Viewed,
            InvoiceStatus::Partial,
            InvoiceStatus::Overdue,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => __('Reminders can only be sent for unpaid sent invoices.'),
            ]);
        }

        $invoice->loadMissing(['client', 'team', 'lineItems']);

        $email = trim((string) ($invoice->client?->email ?? ''));
        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => __('Add an email address on the client before sending a reminder.'),
            ]);
        }

        $amountDue = max(
            0,
            (int) $invoice->getRawOriginal('total_cents') - (int) $invoice->getRawOriginal('amount_paid_cents')
        );
        if ($amountDue < 1) {
            throw ValidationException::withMessages([
                'amount' => __('This invoice has no outstanding balance to remind about.'),
            ]);
        }

        $pdfMedia = $this->safelyGeneratePdf($invoice);

        return DB::transaction(function () use ($invoice, $pdfMedia, $email): Invoice {
            $fresh = $invoice->fresh(['client', 'team']);

            DB::afterCommit(function () use ($fresh, $pdfMedia, $email): void {
                Mail::to($email)->queue(new InvoiceReminderMailer($fresh, $pdfMedia?->id));
            });

            if (function_exists('activity')) {
                activity()
                    ->performedOn($invoice)
                    ->withProperties(['status' => $invoice->status->value])
                    ->log('invoice_reminder_sent');
            }

            Log::info('Invoice reminder queued for delivery', [
                'invoice_id' => $invoice->id,
                'team_id' => $invoice->team_id,
                'client_id' => $invoice->client_id,
                'client_email' => $email,
                'pdf_media_id' => $pdfMedia?->id,
            ]);

            return $invoice->refresh();
        });
    }

    private function safelyGeneratePdf(Invoice $invoice): ?Media
    {
        try {
            return $this->invoicePdfService->generate($invoice);
        } catch (Throwable $e) {
            Log::warning('Invoice PDF generation failed; sending reminder without attachment', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
