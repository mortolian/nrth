<?php

namespace App\Http\Controllers\Web\Tax;

use App\Domain\Accounting\Enums\TaxLineType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tax\Enums\TaxPeriodStatus;
use App\Domain\Tax\Enums\TaxPeriodType;
use App\Domain\Tax\Models\TaxPeriod;
use App\Domain\Tax\Models\VATReturn;
use App\Domain\Tax\Services\VATService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\RedirectResponse;
use Inertia\Response;

class VATController extends Controller
{
    public function __construct(
        private readonly VATService $vatService,
    ) {}

    public function index(Request $request): Response
    {
        if (! Schema::hasTable('tax_periods')) {
            return Inertia::render('Tax/VAT/Index', [
                'current_period' => null,
                'vat_summary' => [
                    'output_vat' => 0,
                    'input_vat' => 0,
                    'net_vat' => 0,
                    'transaction_count' => 0,
                ],
                'periods' => [],
                'vat_transactions' => new LengthAwarePaginator([], 0, 20),
            ]);
        }

        $team = $request->user()->currentTeam;
        $teamId = (int) $team->id;

        $period = TaxPeriod::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TaxPeriodType::VAT->value)
            ->orderByDesc('period_start')
            ->first();

        if ($period === null) {
            $start = now()->startOfMonth();
            $end = now()->copy()->addMonth()->endOfMonth();
            $period = TaxPeriod::queryWithoutTeamScope()->create([
                'team_id' => $teamId,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'type' => TaxPeriodType::VAT->value,
                'status' => TaxPeriodStatus::Open->value,
                'due_date' => $end->copy()->addDays(25)->toDateString(),
            ]);
        }

        $summaryDto = $this->vatService->getVATSummary($team, $period);
        VATReturn::queryWithoutTeamScope()->updateOrCreate(
            ['tax_period_id' => $period->id],
            [
                'team_id' => $period->team_id,
                'output_vat_cents' => $summaryDto->outputVAT->getMinorAmount()->toInt(),
                'input_vat_cents' => $summaryDto->inputVAT->getMinorAmount()->toInt(),
                'net_vat_cents' => $summaryDto->netVAT->getMinorAmount()->toInt(),
                'period_start' => $period->period_start->toDateString(),
                'period_end' => $period->period_end->toDateString(),
            ]
        );

        $outputTransactions = Invoice::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereBetween('issue_date', [$period->period_start->toDateString(), $period->period_end->toDateString()])
            ->whereNotIn('status', [InvoiceStatus::Void->value])
            ->where('vat_amount_cents', '>', 0)
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'id' => 'inv-'.$invoice->id,
                'date' => optional($invoice->issue_date)->toDateString(),
                'reference' => $invoice->number,
                'description' => 'Invoice '.$invoice->number,
                'excl_vat' => (int) $invoice->getRawOriginal('subtotal_cents'),
                'vat_rate' => $invoice->vatRate(),
                'vat_amount' => (int) $invoice->getRawOriginal('vat_amount_cents'),
                'type' => 'output',
            ]);

        $inputTransactions = Transaction::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('status', TransactionStatus::Posted->value)
            ->whereBetween('transaction_date', [$period->period_start->toDateString(), $period->period_end->toDateString()])
            ->whereHas('taxLines', fn ($q) => $q->where('type', TaxLineType::Input->value))
            ->with(['taxLines' => fn ($q) => $q->where('type', TaxLineType::Input->value)])
            ->get()
            ->flatMap(function (Transaction $transaction) {
                return $transaction->taxLines->map(fn ($line): array => [
                    'id' => 'txn-'.$transaction->id.'-'.$line->id,
                    'date' => optional($transaction->transaction_date)->toDateString(),
                    'reference' => $transaction->reference ?: 'TXN-'.$transaction->id,
                    'description' => $transaction->description ?: 'Expense transaction',
                    'excl_vat' => (int) $line->getRawOriginal('taxable_amount_cents'),
                    'vat_rate' => (int) $line->getRawOriginal('taxable_amount_cents') > 0
                        ? round((int) $line->getRawOriginal('tax_amount_cents') / (int) $line->getRawOriginal('taxable_amount_cents'), 4)
                        : 0.0,
                    'vat_amount' => (int) $line->getRawOriginal('tax_amount_cents'),
                    'type' => 'input',
                ]);
            });

        $allTransactions = $outputTransactions
            ->concat($inputTransactions)
            ->sortByDesc(fn (array $row) => $row['date'] ?? '')
            ->values();

        $perPage = 20;
        $page = max(1, (int) $request->integer('page', 1));
        $items = $allTransactions->slice(($page - 1) * $perPage, $perPage)->values();
        $vatTransactions = new LengthAwarePaginator(
            $items,
            $allTransactions->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $periods = TaxPeriod::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('type', TaxPeriodType::VAT->value)
            ->with('vatReturn')
            ->orderByDesc('period_start')
            ->limit(12)
            ->get()
            ->map(function (TaxPeriod $row): array {
                $vatReturn = $row->vatReturn;

                return [
                    'id' => $row->id,
                    'period_start' => optional($row->period_start)->toDateString(),
                    'period_end' => optional($row->period_end)->toDateString(),
                    'status' => $row->status->value,
                    'submitted_at' => optional($row->submitted_at)?->toDateTimeString(),
                    'output_vat' => (int) ($vatReturn?->output_vat_cents ?? 0),
                    'input_vat' => (int) ($vatReturn?->input_vat_cents ?? 0),
                    'net_vat' => (int) ($vatReturn?->net_vat_cents ?? 0),
                ];
            })
            ->values()
            ->all();

        $dueInDays = null;
        if ($period->due_date instanceof Carbon) {
            $dueInDays = now()->diffInDays($period->due_date, false);
        }

        return Inertia::render('Tax/VAT/Index', [
            'current_period' => [
                'id' => $period->id,
                'period_start' => $period->period_start->toDateString(),
                'period_end' => $period->period_end->toDateString(),
                'due_date' => optional($period->due_date)?->toDateString(),
                'due_in_days' => $dueInDays,
                'status' => $period->status->value,
            ],
            'vat_summary' => [
                'output_vat' => $summaryDto->outputVAT->getMinorAmount()->toInt(),
                'input_vat' => $summaryDto->inputVAT->getMinorAmount()->toInt(),
                'net_vat' => $summaryDto->netVAT->getMinorAmount()->toInt(),
                'transaction_count' => $allTransactions->count(),
            ],
            'periods' => $periods,
            'vat_transactions' => $vatTransactions,
        ]);
    }

    public function submit(Request $request, TaxPeriod $period): RedirectResponse
    {
        abort_unless($period->team_id === $request->user()->current_team_id, 403);
        abort_unless($period->type === TaxPeriodType::VAT, 400);

        $period->status = TaxPeriodStatus::Submitted;
        $period->submitted_at = now();
        $period->save();

        return to_route('tax.vat.index');
    }
}
