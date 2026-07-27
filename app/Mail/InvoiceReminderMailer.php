<?php

namespace App\Mail;

use App\Domain\Invoicing\Models\Invoice;
use App\Support\FormatMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class InvoiceReminderMailer extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public ?int $pdfMediaId = null,
    ) {}

    public function envelope(): Envelope
    {
        $this->invoice->loadMissing(['team', 'client']);
        $issuer = $this->issuerName();

        return new Envelope(
            subject: 'Payment reminder: invoice '.$this->invoice->number.' from '.$issuer,
        );
    }

    public function content(): Content
    {
        $this->invoice->loadMissing(['team', 'client']);
        $isTax = $this->invoice->team?->chargesVat() ?? false;
        $total = (int) $this->invoice->getRawOriginal('total_cents');
        $paid = (int) $this->invoice->getRawOriginal('amount_paid_cents');
        $amountDueCents = max(0, $total - $paid);
        $clientName = (string) ($this->invoice->client?->contact_name
            ?: $this->invoice->client?->name
            ?: 'there');

        return new Content(
            markdown: 'emails.invoice-reminder',
            with: [
                'invoice' => $this->invoice,
                'is_tax_invoice' => $isTax,
                'doc_label' => $isTax ? 'tax invoice' : 'invoice',
                'issuer_name' => $this->issuerName(),
                'client_name' => $clientName,
                'issue_date' => optional($this->invoice->issue_date)->format('d M Y') ?? '—',
                'due_date' => optional($this->invoice->due_date)->format('d M Y') ?? '—',
                'amount_due' => FormatMoney::minorUnits(
                    $amountDueCents,
                    (string) ($this->invoice->currency ?? 'ZAR'),
                ),
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

        $fileName = $this->invoice->number.'.pdf';
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
        return $this->invoice->team !== null
            ? $this->invoice->team->issuerForInvoicingDocuments()['name']
            : (string) config('app.name');
    }
}
