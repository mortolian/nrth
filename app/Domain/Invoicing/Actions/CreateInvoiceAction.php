<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\DTOs\CreateInvoiceDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLineItem;
use App\Domain\Invoicing\Services\InvoiceBusinessCurrencySnapshot;
use App\Domain\Invoicing\Services\InvoiceNumberService;
use App\Domain\Invoicing\Services\InvoiceTotalsCalculator;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CreateInvoiceAction
{
    public function __construct(
        private readonly InvoiceNumberService $numberService,
        private readonly InvoiceBusinessCurrencySnapshot $businessCurrencySnapshot,
        private readonly InvoiceTotalsCalculator $totalsCalculator,
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
                'discount_type' => $dto->discountType,
                'discount_percent' => $dto->discountType === 'percent' ? $dto->discountPercent : null,
                'discount_cents' => $dto->discountType === 'fixed' ? $dto->discountCents : null,
                'income_account_id' => $dto->incomeAccountId,
            ]);

            $team = Team::query()->findOrFail($dto->teamId);
            $chargesVat = $team->chargesVat();
            $defaultLineVatRate = $team->defaultVatRateForInvoicing();

            $calculatorLines = [];
            foreach ($dto->lineItems as $line) {
                $vatRate = $chargesVat
                    ? (array_key_exists('vat_rate', $line) ? (float) $line['vat_rate'] : $defaultLineVatRate)
                    : 0.0;

                $calculatorLines[] = [
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => $line['unit_price_cents'],
                    'vat_rate' => $vatRate,
                    'discount_type' => $line['discount_type'] ?? null,
                    'discount_percent' => $line['discount_percent'] ?? null,
                    'discount_cents' => $line['discount_cents'] ?? null,
                ];
            }

            $totals = $this->totalsCalculator->calculate(
                $calculatorLines,
                $dto->discountType,
                $dto->discountPercent,
                $dto->discountCents,
            );

            foreach ($dto->lineItems as $index => $line) {
                $computed = $totals['lines'][$index];
                $vatRate = $chargesVat
                    ? (array_key_exists('vat_rate', $line) ? (float) $line['vat_rate'] : $defaultLineVatRate)
                    : 0.0;

                InvoiceLineItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'item_id' => isset($line['item_id']) ? (int) $line['item_id'] : null,
                    'income_account_id' => isset($line['income_account_id']) ? (int) $line['income_account_id'] : null,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => (int) $line['unit_price_cents'],
                    'vat_rate' => $vatRate,
                    'discount_type' => $line['discount_type'] ?? null,
                    'discount_percent' => ($line['discount_type'] ?? null) === 'percent' ? ($line['discount_percent'] ?? null) : null,
                    'discount_cents' => ($line['discount_type'] ?? null) === 'fixed' ? ($line['discount_cents'] ?? null) : null,
                    'discount_amount_cents' => $computed['line_discount_cents'],
                    'vat_amount_cents' => $computed['vat_amount_cents'],
                    'total_cents' => $computed['total_cents'],
                    'sort_order' => $index,
                ]);
            }

            $invoice->update([
                'subtotal_cents' => $totals['subtotal_cents'],
                'vat_amount_cents' => $totals['vat_amount_cents'],
                'total_cents' => $totals['total_cents'],
                'discount_total_cents' => $totals['discount_total_cents'],
            ]);

            $invoice->refresh();
            $this->businessCurrencySnapshot->sync($invoice);

            return $invoice->refresh();
        });
    }
}
