<?php

namespace App\Mail;

use App\Domain\Invoicing\Models\Estimate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class EstimateMailer extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Estimate $estimate,
        public ?Media $pdfMedia = null,
    ) {}

    public function envelope(): Envelope
    {
        $this->estimate->loadMissing('team');
        $fromName = $this->estimate->team !== null
            ? $this->estimate->team->issuerForInvoicingDocuments('estimate')['name']
            : config('app.name');

        return new Envelope(
            subject: 'Estimate '.$this->estimate->number.' from '.$fromName,
        );
    }

    public function content(): Content
    {
        $this->estimate->loadMissing('team');
        $issuerName = $this->estimate->team !== null
            ? $this->estimate->team->issuerForInvoicingDocuments('estimate')['name']
            : config('app.name');

        return new Content(
            view: 'emails.estimate',
            with: [
                'estimate' => $this->estimate,
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
