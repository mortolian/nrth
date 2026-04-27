<?php

namespace App\Mail;

use App\Domain\Invoicing\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class QuoteMailer extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Quote $quote,
        public ?Media $pdfMedia = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Quote '.$this->quote->number.' from '.($this->quote->team?->name ?? config('app.name')),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quote',
            with: ['quote' => $this->quote],
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

