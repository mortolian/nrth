<?php

namespace App\Domain\Reports\Services;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\Team;
use Carbon\CarbonInterface;

final class AgedReceivablesService
{
    public const MAX_PDF_INVOICES = 1500;

    /**
     * @return array{
     *     as_of: string,
     *     clients: array<int, array<string, mixed>>,
     *     totals: array<string, int>,
     *     invoice_count: int
     * }
     */
    public function asOf(int $teamId, CarbonInterface $asOf): array
    {
        $asOfDate = $asOf->toDateString();
        $asOfDay = $asOf->copy()->startOfDay();

        $invoices = Invoice::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereIn('status', InvoiceStatus::issuedValues())
            ->whereDate('issue_date', '<=', $asOfDate)
            ->with([
                'client' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'name'),
                'payments' => fn ($query) => $query->withoutGlobalScopes()->where('team_id', $teamId),
            ])
            ->orderBy('due_date')
            ->orderBy('number')
            ->get();

        /** @var array<int, array<string, mixed>> $clients */
        $clients = [];

        foreach ($invoices as $invoice) {
            $outstanding = $this->outstandingBookCents($invoice, $asOfDate);
            if ($outstanding <= 0) {
                continue;
            }

            $due = $invoice->due_date?->copy()->startOfDay() ?? $asOfDay->copy();
            $daysOverdue = $due->lt($asOfDay) ? (int) $due->diffInDays($asOfDay) : 0;
            $bucket = $this->bucket($daysOverdue);
            $clientId = (int) ($invoice->client_id ?? 0);

            if (! isset($clients[$clientId])) {
                $clients[$clientId] = [
                    'client_id' => $clientId,
                    'name' => $invoice->client?->name ?? 'Unknown client',
                    'current' => 0,
                    'days_1_30' => 0,
                    'days_31_60' => 0,
                    'days_61_90' => 0,
                    'over_90' => 0,
                    'total' => 0,
                    'invoices' => [],
                ];
            }

            $clients[$clientId][$bucket] += $outstanding;
            $clients[$clientId]['total'] += $outstanding;
            $clients[$clientId]['invoices'][] = [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'days_overdue' => $daysOverdue,
                'bucket' => $bucket,
                'amount_cents' => $outstanding,
                'currency' => (string) ($invoice->currency ?? 'ZAR'),
            ];
        }

        $rows = array_values($clients);
        usort($rows, fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        $totals = $this->emptyBuckets();
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }
        }

        return [
            'as_of' => $asOfDate,
            'clients' => $rows,
            'totals' => $totals,
            'invoice_count' => array_sum(array_map(fn (array $row): int => count($row['invoices']), $rows)),
        ];
    }

    public function currency(Team $team): string
    {
        return ReportPeriod::currency($team);
    }

    private function outstandingBookCents(Invoice $invoice, string $asOfDate): int
    {
        $total = (int) $invoice->getRawOriginal('total_cents');
        $paid = 0;

        foreach ($invoice->payments as $payment) {
            $paidOn = $payment->payment_date?->toDateString();
            if ($paidOn !== null && $paidOn > $asOfDate) {
                continue;
            }
            $paid += (int) $payment->getRawOriginal('amount_cents');
        }

        $due = max(0, $total - $paid);
        if ($due === 0 || $total <= 0) {
            return 0;
        }

        $bookTotal = $invoice->totalBusinessCurrencyCents();

        return (int) round($due * $bookTotal / $total);
    }

    private function bucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => 'days_1_30',
            $daysOverdue <= 60 => 'days_31_60',
            $daysOverdue <= 90 => 'days_61_90',
            default => 'over_90',
        };
    }

    /**
     * @return array{current: int, days_1_30: int, days_31_60: int, days_61_90: int, over_90: int, total: int}
     */
    private function emptyBuckets(): array
    {
        return [
            'current' => 0,
            'days_1_30' => 0,
            'days_31_60' => 0,
            'days_61_90' => 0,
            'over_90' => 0,
            'total' => 0,
        ];
    }
}
