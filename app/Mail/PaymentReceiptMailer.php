<?php

namespace App\Mail;

use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\Services\PaymentReceiptPdfService;
use App\Support\FormatMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PaymentReceiptMailer extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Payment $payment,
        public ?int $pdfMediaId = null,
    ) {}

    public function envelope(): Envelope
    {
        $this->payment->loadMissing(['invoice', 'team']);

        $invoiceNumber = (string) ($this->payment->invoice?->number ?? '');

        return new Envelope(
            subject: 'Payment receipt for invoice '.$invoiceNumber,
        );
    }

    public function content(): Content
    {
        $this->payment->loadMissing(['invoice.client', 'team']);
        $invoice = $this->payment->invoice;
        $totals = app(PaymentReceiptPdfService::class)->totalsAsOfPayment($this->payment);
        $currency = (string) ($this->payment->currency ?: $invoice?->currency ?: 'ZAR');

        return new Content(
            markdown: 'emails.payment-receipt',
            with: [
                'payment' => $this->payment,
                'invoice' => $invoice,
                'issuer_name' => $this->issuerName(),
                'client_name' => (string) ($invoice?->client?->contact_name
                    ?: $invoice?->client?->name
                    ?: 'there'),
                'payment_date' => optional($this->payment->payment_date)->format('d M Y') ?? '—',
                'amount_received' => FormatMoney::minorUnits($totals['payment_cents'], $currency),
                'invoice_total' => FormatMoney::minorUnits($totals['invoice_total_cents'], $currency),
                'outstanding' => FormatMoney::minorUnits($totals['outstanding_cents'], $currency),
                'has_attachment' => $this->pdfAttachmentPath() !== null,
            ],
        );
    }

    public function attachments(): array
    {
        $path = $this->pdfAttachmentPath();
        if ($path === null) {
            return [];
        }

        $fileName = 'receipt.pdf';
        if ($this->pdfMediaId !== null) {
            $media = Media::query()->find($this->pdfMediaId);
            if ($media?->file_name) {
                $fileName = $media->file_name;
            }
        }

        return [
            Attachment::fromPath($path)
                ->as($fileName)
                ->withMime('application/pdf'),
        ];
    }

    private function pdfAttachmentPath(): ?string
    {
        if ($this->pdfMediaId === null) {
            return null;
        }

        $media = Media::query()->find($this->pdfMediaId);
        if ($media === null) {
            return null;
        }

        $path = $media->getPath();
        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        return $path;
    }

    private function issuerName(): string
    {
        return $this->payment->team !== null
            ? $this->payment->team->issuerForInvoicingDocuments()['name']
            : (string) config('app.name');
    }
}
