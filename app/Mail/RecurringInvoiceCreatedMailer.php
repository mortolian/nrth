<?php

namespace App\Mail;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Support\FormatMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecurringInvoiceCreatedMailer extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Invoice $invoice,
    ) {}

    public function envelope(): Envelope
    {
        $this->prepare();

        return new Envelope(
            subject: 'Invoice '.$this->number().' added',
        );
    }

    public function content(): Content
    {
        $this->prepare();
        $status = $this->invoice->status;

        return new Content(
            markdown: 'emails.recurring-invoice-created',
            with: [
                'number' => $this->number(),
                'client_name' => $this->clientName(),
                'issue_date' => optional($this->invoice->issue_date)->format('d M Y') ?? '—',
                'due_date' => optional($this->invoice->due_date)->format('d M Y') ?? '—',
                'total' => FormatMoney::minorUnits(
                    (int) $this->invoice->getRawOriginal('total_cents'),
                    (string) ($this->invoice->currency ?? 'ZAR'),
                ),
                'status' => $status instanceof InvoiceStatus ? ucfirst($status->value) : 'Draft',
                'status_note' => $status === InvoiceStatus::Sent
                    ? 'It was sent to the client.'
                    : 'It is saved as a draft.',
                'team_name' => $this->invoice->team?->name ?? (string) config('app.name'),
                'invoice_url' => route('invoicing.invoices.show', $this->invoice),
            ],
        );
    }

    private function prepare(): void
    {
        $this->invoice->loadMissing('team');
        $this->invoice->loadClientWithoutTeamScope();
    }

    private function number(): string
    {
        $number = trim((string) ($this->invoice->number ?? ''));

        return $number !== '' ? $number : 'invoice';
    }

    private function clientName(): string
    {
        $name = trim((string) ($this->invoice->client?->name ?? ''));

        return $name !== '' ? $name : '—';
    }
}
