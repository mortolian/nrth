<?php

namespace App\Mail;

use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class InvoiceMailer extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public ?Media $pdfMedia = null,
    ) {}

    public function envelope(): Envelope
    {
        $this->invoice->loadMissing('team');
        $prefix = $this->invoice->team?->chargesVat() ? 'Tax invoice' : 'Invoice';
        $fromName = $this->invoice->team !== null
            ? $this->invoice->team->issuerForInvoicingDocuments()['name']
            : config('app.name');

        return new Envelope(
            subject: $prefix.' '.$this->invoice->number.' from '.$fromName,
        );
    }

    public function content(): Content
    {
        $this->invoice->loadMissing('team');
        $issuerName = $this->invoice->team !== null
            ? $this->invoice->team->issuerForInvoicingDocuments()['name']
            : config('app.name');

        return new Content(
            view: 'emails.invoice',
            with: [
                'invoice' => $this->invoice,
                'is_tax_invoice' => $this->invoice->team?->chargesVat() ?? false,
                'issuer_name' => $issuerName,
            ],
        );
    }

    public function attachments(): array
    {
        if ($this->pdfMedia === null) {
            return [];
        }

        return [
            Attachment::fromStorageDisk(
                $this->pdfMedia->disk,
                $this->pdfMedia->getPathRelativeToRoot(),
            )->as($this->pdfMedia->file_name),
        ];
    }
}
