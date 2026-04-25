<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\DTOs\CreateInvoiceDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLineItem;
use App\Domain\Invoicing\Services\InvoiceNumberService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CreateInvoiceAction
{
    public function __construct(
        private readonly InvoiceNumberService $numberService,
    ) {}

    public function execute(CreateInvoiceDTO $dto): Invoice
    {
        return DB::transaction(function () use ($dto): Invoice {
            $client = Client::queryWithoutTeamScope()
                ->where('team_id', $dto->teamId)
                ->findOrFail($dto->clientId);

            $issueDate = Carbon::parse($dto->issueDate);
            $dueDate = $dto->dueDate !== null
                ? Carbon::parse($dto->dueDate)
                : $issueDate->copy()->addDays((int) $client->payment_terms_days);

            $invoice = Invoice::queryWithoutTeamScope()->create([
                'team_id' => $dto->teamId,
                'client_id' => $dto->clientId,
                'status' => InvoiceStatus::Draft,
                'number' => $this->numberService->generate($dto->teamId, $issueDate),
                'reference' => $dto->reference,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'subtotal_cents' => 0,
                'vat_amount_cents' => 0,
                'total_cents' => 0,
                'amount_paid_cents' => 0,
                'currency' => $dto->currency,
                'notes' => $dto->notes,
                'footer' => $dto->footer,
            ]);

            $subtotalCents = 0;
            $vatAmountCents = 0;

            foreach ($dto->lineItems as $index => $line) {
                $quantity = (float) $line['quantity'];
                $unitPriceCents = (int) $line['unit_price_cents'];
                $vatRate = array_key_exists('vat_rate', $line) ? (float) $line['vat_rate'] : 0.15;

                $lineSubtotal = (int) round($quantity * $unitPriceCents);
                $lineVat = (int) round($lineSubtotal * $vatRate);
                $lineTotal = $lineSubtotal + $lineVat;

                InvoiceLineItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'description' => $line['description'],
                    'quantity' => $quantity,
                    'unit_price_cents' => $unitPriceCents,
                    'vat_rate' => $vatRate,
                    'vat_amount_cents' => $lineVat,
                    'total_cents' => $lineTotal,
                    'sort_order' => $index,
                ]);

                $subtotalCents += $lineSubtotal;
                $vatAmountCents += $lineVat;
            }

            $invoice->update([
                'subtotal_cents' => $subtotalCents,
                'vat_amount_cents' => $vatAmountCents,
                'total_cents' => $subtotalCents + $vatAmountCents,
            ]);

            return $invoice->refresh();
        });
    }
}
