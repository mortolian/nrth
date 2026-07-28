<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;

class MarkInvoiceViewedAction
{
    public function execute(Invoice $invoice): Invoice
    {
        if (in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Void], true)) {
            return $invoice;
        }

        $updates = [];
        if ($invoice->viewed_at === null) {
            $updates['viewed_at'] = now();
        }

        if ($invoice->status === InvoiceStatus::Sent) {
            $updates['status'] = InvoiceStatus::Viewed;
        }

        if ($updates !== []) {
            $invoice->forceFill($updates)->save();
        }

        return $invoice->fresh() ?? $invoice;
    }
}
