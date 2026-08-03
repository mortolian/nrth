<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Models\Invoice;

/**
 * Reserved for a future public invoice portal.
 *
 * Invoice email is PDF-only today and Viewed is not part of the product flow,
 * so this intentionally does nothing. Re-enable when clients can open a
 * reachable public link that should set viewed_at / status.
 */
class MarkInvoiceViewedAction
{
    public function execute(Invoice $invoice): Invoice
    {
        return $invoice;
    }
}
