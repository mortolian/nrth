<?php

namespace App\Mail;

use App\Domain\Accounting\Models\Supplier;
use App\Domain\Accounting\Models\Transaction;
use App\Support\FormatMoney;
use App\Support\Iso4217Currencies;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecurringExpenseCreatedMailer extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Transaction $transaction,
    ) {}

    public function envelope(): Envelope
    {
        $this->prepare();

        return new Envelope(
            subject: 'Expense added: '.$this->label(),
        );
    }

    public function content(): Content
    {
        $this->prepare();
        $currency = Iso4217Currencies::normalize(
            (string) ($this->transaction->team?->mergedBusinessSettings()['invoice_default_currency'] ?? 'ZAR'),
        );

        return new Content(
            markdown: 'emails.recurring-expense-created',
            with: [
                'label' => $this->label(),
                'supplier' => $this->transaction->displaySupplier() ?? '—',
                'expense_date' => optional($this->transaction->transaction_date)->format('d M Y') ?? '—',
                'total' => FormatMoney::minorUnits($this->transaction->journalTotalCents(), $currency),
                'team_name' => $this->transaction->team?->name ?? (string) config('app.name'),
                'expense_url' => route('expenses.edit', $this->transaction),
            ],
        );
    }

    private function prepare(): void
    {
        $this->transaction->loadMissing(['team', 'journalEntries']);

        if ($this->transaction->supplier_id === null) {
            $this->transaction->setRelation('supplier', null);

            return;
        }

        $this->transaction->setRelation(
            'supplier',
            Supplier::queryWithoutTeamScope()
                ->where('team_id', $this->transaction->team_id)
                ->whereKey($this->transaction->supplier_id)
                ->first(),
        );
    }

    private function label(): string
    {
        $description = trim((string) ($this->transaction->description ?? ''));

        return $description !== '' ? $description : 'Expense #'.$this->transaction->id;
    }
}
