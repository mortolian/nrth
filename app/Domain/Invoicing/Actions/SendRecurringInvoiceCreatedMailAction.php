<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Models\Invoice;
use App\Mail\RecurringInvoiceCreatedMailer;
use App\Support\TeamMailRecipients;
use Illuminate\Support\Facades\Mail;

class SendRecurringInvoiceCreatedMailAction
{
    public function execute(Invoice $invoice): void
    {
        $invoice->loadMissing('team');
        $team = $invoice->team;
        if ($team === null) {
            return;
        }

        foreach (TeamMailRecipients::for($team, 'invoices.view', 'notify_recurring_invoice') as $user) {
            Mail::to($user->email)->queue(new RecurringInvoiceCreatedMailer($invoice));
        }
    }
}
