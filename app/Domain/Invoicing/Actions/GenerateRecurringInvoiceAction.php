<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\DTOs\CreateInvoiceDTO;
use App\Domain\Invoicing\Enums\RecurringInvoiceStatus;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\RecurringInvoice;
use App\Domain\Invoicing\Services\RecurringDueDateResolver;
use App\Domain\Invoicing\Services\RecurringPlaceholderResolver;
use App\Domain\Invoicing\Services\RecurringScheduleResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateRecurringInvoiceAction
{
    public function __construct(
        private readonly CreateInvoiceAction $createInvoiceAction,
        private readonly SendInvoiceAction $sendInvoiceAction,
        private readonly RecurringDueDateResolver $dueDateResolver,
        private readonly RecurringScheduleResolver $scheduleResolver,
    ) {}

    public function execute(RecurringInvoice $recurring, ?Carbon $runDate = null): ?Invoice
    {
        return DB::transaction(function () use ($recurring, $runDate): ?Invoice {
            /** @var RecurringInvoice $recurring */
            $recurring = RecurringInvoice::queryWithoutTeamScope()
                ->lockForUpdate()
                ->findOrFail($recurring->id);

            if ($recurring->status !== RecurringInvoiceStatus::Active) {
                return null;
            }

            $client = Client::queryWithoutTeamScope()
                ->where('team_id', $recurring->team_id)
                ->find($recurring->client_id);

            if ($client === null || ! $client->is_active) {
                return null;
            }

            $issueDate = ($runDate ?? Carbon::today())->copy()->startOfDay();
            $dueDate = $this->dueDateResolver->resolve(
                $issueDate,
                $recurring->due_date_rule,
                $recurring->due_days,
                $recurring->due_on_day,
                (int) $client->payment_terms_days,
            );

            $lineItems = collect((array) $recurring->line_items)
                ->map(function (array $line) use ($issueDate, $dueDate, $recurring): array {
                    return [
                        'description' => (string) RecurringPlaceholderResolver::replace(
                            (string) ($line['description'] ?? ''),
                            $issueDate,
                            $dueDate,
                            (int) $recurring->period_offset_months,
                        ),
                        'quantity' => $line['quantity'] ?? 1,
                        'unit_price_cents' => (int) ($line['unit_price_cents'] ?? 0),
                        'vat_rate' => array_key_exists('vat_rate', $line) && $line['vat_rate'] !== null
                            ? (float) $line['vat_rate']
                            : null,
                        'item_id' => isset($line['item_id']) ? (int) $line['item_id'] : null,
                    ];
                })
                ->values()
                ->all();

            $resolveText = fn (?string $text): ?string => RecurringPlaceholderResolver::replace(
                $text,
                $issueDate,
                $dueDate,
                (int) $recurring->period_offset_months,
            );

            $invoice = $this->createInvoiceAction->execute(new CreateInvoiceDTO(
                teamId: (int) $recurring->team_id,
                clientId: (int) $recurring->client_id,
                issueDate: $issueDate->toDateString(),
                dueDate: $dueDate->toDateString(),
                currency: (string) $recurring->currency,
                reference: $resolveText($recurring->reference),
                notes: $resolveText($recurring->notes),
                footer: $resolveText($recurring->footer),
                lineItems: $lineItems,
            ));

            $invoice->forceFill(['recurring_invoice_id' => $recurring->id])->save();

            if ($recurring->auto_send) {
                $email = trim((string) ($client->email ?? ''));
                if ($email !== '') {
                    try {
                        $this->sendInvoiceAction->execute($invoice);
                    } catch (Throwable $e) {
                        Log::warning('Recurring invoice auto-send failed; left as draft', [
                            'recurring_invoice_id' => $recurring->id,
                            'invoice_id' => $invoice->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $recurring->generated_count = (int) $recurring->generated_count + 1;
            $recurring->last_generated_at = now();
            $nextRunDate = $this->scheduleResolver->nextRunDateAfter($recurring, $issueDate);
            $recurring->next_run_date = $nextRunDate->toDateString();

            if (
                $recurring->limit_type === RecurringLimitType::Count
                && $recurring->limit_count !== null
                && $recurring->generated_count >= (int) $recurring->limit_count
            ) {
                $recurring->status = RecurringInvoiceStatus::Completed;
            } elseif (
                $recurring->limit_type === RecurringLimitType::EndDate
                && $recurring->limit_end_date !== null
                && $nextRunDate->gt($recurring->limit_end_date)
            ) {
                $recurring->status = RecurringInvoiceStatus::Completed;
            }

            $recurring->save();

            return $invoice->fresh();
        });
    }
}
