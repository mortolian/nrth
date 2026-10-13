<?php

namespace App\Domain\Reports\Services;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Models\Team;
use Carbon\CarbonInterface;

final class ClientStatementService
{
    public const MAX_PDF_LINES = 1500;

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function clients(int $teamId): array
    {
        return Client::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
            ])
            ->all();
    }

    public function findClient(int $teamId, int $clientId): ?Client
    {
        return Client::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereKey($clientId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function statement(int $teamId, Client $client, CarbonInterface $from, CarbonInterface $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $invoices = Invoice::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('client_id', $client->id)
            ->whereIn('status', InvoiceStatus::issuedValues())
            ->with([
                'payments' => fn ($query) => $query->withoutGlobalScopes()->where('team_id', $teamId),
            ])
            ->orderBy('issue_date')
            ->orderBy('number')
            ->get();

        $opening = 0;
        $lines = [];

        foreach ($invoices as $invoice) {
            $issueDate = $invoice->issue_date?->toDateString();
            if ($issueDate === null || $issueDate > $toDate) {
                continue;
            }

            $charge = $invoice->totalBusinessCurrencyCents();
            if ($issueDate < $fromDate) {
                $opening += $charge;
            } else {
                $lines[] = [
                    'date' => $issueDate,
                    'sort' => 0,
                    'type' => 'invoice',
                    'reference' => (string) $invoice->number,
                    'detail' => 'Invoice',
                    'invoice_id' => $invoice->id,
                    'charge_cents' => $charge,
                    'credit_cents' => 0,
                ];
            }

            foreach ($invoice->payments as $payment) {
                $paidOn = $payment->payment_date?->toDateString();
                if ($paidOn === null || $paidOn > $toDate) {
                    continue;
                }
                $credit = $this->paymentBookCents($payment);
                if ($paidOn < $fromDate) {
                    $opening -= $credit;

                    continue;
                }
                $lines[] = [
                    'date' => $paidOn,
                    'sort' => 1,
                    'type' => 'payment',
                    'reference' => $payment->reference ?: (string) $invoice->number,
                    'detail' => 'Payment · '.$invoice->number,
                    'invoice_id' => $invoice->id,
                    'charge_cents' => 0,
                    'credit_cents' => $credit,
                ];
            }
        }

        usort($lines, function (array $a, array $b): int {
            return [$a['date'], $a['sort'], $a['reference']] <=> [$b['date'], $b['sort'], $b['reference']];
        });

        $balance = $opening;
        $charges = 0;
        $credits = 0;
        foreach ($lines as $index => $line) {
            $charges += (int) $line['charge_cents'];
            $credits += (int) $line['credit_cents'];
            $balance += (int) $line['charge_cents'] - (int) $line['credit_cents'];
            $lines[$index]['balance_cents'] = $balance;
            unset($lines[$index]['sort']);
        }

        return [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'address_lines' => $this->addressLines($client->address),
            ],
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'opening_cents' => $opening,
            'charges_cents' => $charges,
            'credits_cents' => $credits,
            'closing_cents' => $balance,
            'lines' => $lines,
        ];
    }

    public function currency(Team $team): string
    {
        return ReportPeriod::currency($team);
    }

    /**
     * @param  array<string, mixed>|null  $address
     * @return list<string>
     */
    private function addressLines(?array $address): array
    {
        if ($address === null) {
            return [];
        }

        $lines = [];
        foreach (['street', 'city', 'province', 'postal_code', 'country'] as $key) {
            $value = trim((string) ($address[$key] ?? ''));
            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return $lines;
    }

    private function paymentBookCents(Payment $payment): int
    {
        $book = $payment->getRawOriginal('bank_amount_business_cents');
        if ($book !== null && $book !== '') {
            return (int) $book;
        }

        return (int) $payment->getRawOriginal('amount_cents');
    }
}
