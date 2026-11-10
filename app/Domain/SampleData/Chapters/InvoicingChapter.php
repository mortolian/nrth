<?php

namespace App\Domain\SampleData\Chapters;

use App\Domain\Invoicing\Actions\CreateInvoiceAction;
use App\Domain\Invoicing\Actions\MarkInvoiceSentAction;
use App\Domain\Invoicing\Actions\RecordPaymentAction;
use App\Domain\Invoicing\DTOs\CreateInvoiceDTO;
use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\EstimateStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Enums\RecurringDueDateRule;
use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Enums\RecurringInvoiceStatus;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Estimate;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Item;
use App\Domain\Invoicing\Models\RecurringInvoice;
use App\Domain\Invoicing\Services\InvoiceTotalsCalculator;
use App\Domain\SampleData\SampleChapter;
use App\Domain\SampleData\SampleContext;
use Carbon\Carbon;

final class InvoicingChapter implements SampleChapter
{
    public function key(): string
    {
        return 'invoicing';
    }

    public function seed(SampleContext $context): void
    {
        $context->clients = [
            'harbour' => $this->client($context, 'Harbour & Co', 'accounts@harbour.example', 14),
            'cedar' => $this->client($context, 'Cedar Clinic', 'finance@cedar.example', 30),
            'veld' => $this->client($context, 'Veld Events', 'hello@veld.example', 14),
            'lumen' => $this->client($context, 'Lumen Schools', 'admin@lumen.example', 30),
        ];

        Item::query()->create([
            'team_id' => $context->team->id,
            'name' => 'Design retainer',
            'description' => 'Monthly brand and design support',
            'unit' => 'month',
            'unit_price_cents' => 1_800_000,
            'default_vat_rate' => 0.15,
            'is_active' => true,
        ]);
        Item::query()->create([
            'team_id' => $context->team->id,
            'name' => 'Workshop day',
            'description' => 'On-site workshop facilitation',
            'unit' => 'day',
            'unit_price_cents' => 650_000,
            'default_vat_rate' => 0.15,
            'is_active' => true,
        ]);

        $create = app(CreateInvoiceAction::class);
        $send = app(MarkInvoiceSentAction::class);
        $pay = app(RecordPaymentAction::class);

        foreach ($context->calendar->postedMonths() as $month) {
            $issue = $month->copy()->day(1);
            if ($issue->greaterThan($context->calendar->asOf)) {
                $issue = $context->calendar->asOf->copy();
            }

            $retainer = $this->invoice($create, $context, 'harbour', $issue, 'Monthly design retainer', 1_800_000);
            $send->execute($retainer);
            $paidOn = $issue->copy()->addDays(7);
            if ($paidOn->lessThanOrEqualTo($context->calendar->asOf)) {
                $this->payment($pay, $context, $retainer, $paidOn, (int) $retainer->getRawOriginal('total_cents'));
            }

            if ((int) $month->month % 2 === 0) {
                $projectDate = $month->copy()->day(min(12, $month->daysInMonth));
                if ($projectDate->lessThanOrEqualTo($context->calendar->asOf)) {
                    $project = $this->invoice($create, $context, 'cedar', $projectDate, 'Clinic wayfinding', 2_400_000);
                    $send->execute($project);
                }
            }
        }

        $this->agedInvoice($create, $send, $context, 'veld', 80, 'Festival identity', 3_200_000);
        $this->agedInvoice($create, $send, $context, 'veld', 45, 'Event signage', 960_000);
        $recent = $this->agedInvoice($create, $send, $context, 'lumen', 20, 'School prospectus', 1_500_000);
        if ($recent !== null) {
            $half = (int) floor(((int) $recent->getRawOriginal('total_cents')) / 2);
            $this->payment($pay, $context, $recent, $context->calendar->asOf->copy(), $half);
        }

        foreach ($context->calendar->futureMonths() as $month) {
            $this->invoice($create, $context, 'harbour', $month->copy()->day(1), 'Monthly design retainer', 1_800_000);
        }

        $this->estimates($context);
        $this->schedule($context);
    }

    private function client(SampleContext $context, string $name, string $email, int $terms): Client
    {
        return Client::query()->create([
            'team_id' => $context->team->id,
            'name' => $name,
            'email' => $email,
            'currency' => 'ZAR',
            'payment_terms_days' => $terms,
            'is_active' => true,
            'address' => [
                'city' => 'Cape Town',
                'country' => 'South Africa',
            ],
        ]);
    }

    private function invoice(
        CreateInvoiceAction $create,
        SampleContext $context,
        string $clientKey,
        Carbon $issue,
        string $description,
        int $unitPriceCents,
    ): Invoice {
        return $create->execute(new CreateInvoiceDTO(
            teamId: $context->team->id,
            clientId: $context->clients[$clientKey]->id,
            issueDate: $issue->toDateString(),
            dueDate: null,
            currency: 'ZAR',
            reference: $description,
            lineItems: [[
                'description' => $description.' · '.$issue->format('F Y'),
                'quantity' => 1,
                'unit_price_cents' => $unitPriceCents,
            ]],
            incomeAccountId: $context->account('4000')->id,
        ));
    }

    private function payment(
        RecordPaymentAction $pay,
        SampleContext $context,
        Invoice $invoice,
        Carbon $date,
        int $amountCents,
    ): void {
        if ($amountCents < 1) {
            return;
        }

        $pay->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: $context->team->id,
            amountCents: $amountCents,
            paymentDate: $date->toDateString(),
            bankingAccountId: $context->bank->id,
            method: PaymentMethod::Eft,
            currency: 'ZAR',
            reference: $invoice->number,
            createdBy: $context->user->id,
        ));
    }

    private function agedInvoice(
        CreateInvoiceAction $create,
        MarkInvoiceSentAction $send,
        SampleContext $context,
        string $clientKey,
        int $daysBefore,
        string $description,
        int $unitPriceCents,
    ): ?Invoice {
        $issue = $context->calendar->asOf->copy()->subDays($daysBefore);
        if (! $context->calendar->contains($issue)) {
            return null;
        }

        $invoice = $this->invoice($create, $context, $clientKey, $issue, $description, $unitPriceCents);
        $send->execute($invoice);

        return $invoice->fresh();
    }

    private function estimates(SampleContext $context): void
    {
        $calculator = app(InvoiceTotalsCalculator::class);
        $issue = $context->calendar->asOf->copy()->subDays(10);
        if ($issue->lessThan($context->calendar->start)) {
            $issue = $context->calendar->start->copy();
        }

        $this->estimate($context, $calculator, 'EST-1001', $context->clients['cedar'], EstimateStatus::Sent, $issue, 'Waiting-room mural', 4_800_000);
        $this->estimate($context, $calculator, 'EST-1002', $context->clients['lumen'], EstimateStatus::Accepted, $issue->copy()->subDays(20), 'Annual report', 2_200_000);
    }

    private function estimate(
        SampleContext $context,
        InvoiceTotalsCalculator $calculator,
        string $number,
        Client $client,
        EstimateStatus $status,
        Carbon $issue,
        string $description,
        int $unitPriceCents,
    ): void {
        if ($issue->lessThan($context->calendar->start)) {
            $issue = $context->calendar->start->copy();
        }

        $totals = $calculator->calculate([[
            'quantity' => 1,
            'unit_price_cents' => $unitPriceCents,
            'vat_rate' => 0.15,
        ]]);
        $line = $totals['lines'][0];

        Estimate::query()->create([
            'team_id' => $context->team->id,
            'client_id' => $client->id,
            'status' => $status,
            'number' => $number,
            'issue_date' => $issue->toDateString(),
            'expiry_date' => $issue->copy()->addDays(30)->toDateString(),
            'subtotal_cents' => $totals['subtotal_cents'],
            'vat_amount_cents' => $totals['vat_amount_cents'],
            'total_cents' => $totals['total_cents'],
            'currency' => 'ZAR',
            'line_items' => [[
                'description' => $description,
                'quantity' => 1,
                'unit_price_cents' => $unitPriceCents,
                'vat_rate' => 0.15,
                'vat_amount_cents' => $line['vat_amount_cents'],
                'total_cents' => $line['total_cents'],
            ]],
            'terms' => '50% deposit on acceptance. Balance due on delivery.',
            'sent_at' => $status === EstimateStatus::Draft ? null : $issue,
            'accepted_at' => $status === EstimateStatus::Accepted ? $issue->copy()->addDays(3) : null,
        ]);
    }

    private function schedule(SampleContext $context): void
    {
        $next = $context->calendar->futureMonths()[0] ?? $context->calendar->end->copy()->addDay();

        RecurringInvoice::query()->create([
            'team_id' => $context->team->id,
            'client_id' => $context->clients['harbour']->id,
            'status' => RecurringInvoiceStatus::Active,
            'frequency' => RecurringFrequency::Monthly,
            'generate_on_day' => 1,
            'limit_type' => RecurringLimitType::None,
            'generated_count' => 0,
            'next_run_date' => $next->toDateString(),
            'auto_send' => false,
            'period_offset_months' => 0,
            'due_date_rule' => RecurringDueDateRule::DaysAfterIssue,
            'due_days' => 14,
            'currency' => 'ZAR',
            'reference' => 'Design retainer',
            'line_items' => [[
                'description' => 'Monthly design retainer for {{month_year}}',
                'quantity' => 1,
                'unit_price_cents' => 1_800_000,
                'vat_rate' => 0.15,
            ]],
        ]);
    }
}
