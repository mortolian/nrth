<?php

namespace App\Http\Controllers\Web\Tax;

use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tax\Enums\TaxPeriodType;
use App\Domain\Tax\Models\TaxPeriod;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class TaxDocumentsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $teamId = (int) $request->user()->current_team_id;
        $year = max(2000, min(2100, (int) $request->integer('year', (int) now()->format('Y'))));
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();

        $invoiceCount = 0;
        $invoiceTotal = 0;
        if (Schema::hasTable('invoices')) {
            $invoiceQuery = Invoice::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()]);
            $invoiceCount = $invoiceQuery->count();
            $invoiceTotal = (int) $invoiceQuery->sum('total_cents');
        }

        $expenseReceiptsCount = 0;
        $expensesMissingReceipts = 0;
        $expenseTotal = 0;
        if (Schema::hasTable('transactions')) {
            $expenses = Transaction::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('type', TransactionType::Expense->value)
                ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
                ->withCount('media')
                ->with(['journalEntries'])
                ->get();

            $expenseReceiptsCount = $expenses->where('media_count', '>', 0)->count();
            $expensesMissingReceipts = $expenses->where('media_count', 0)->count();
            $expenseTotal = (int) $expenses->sum(fn (Transaction $transaction): int => (int) $transaction->journalEntries->sum(fn ($line) => (int) $line->getRawOriginal('amount_cents')));
        }

        $vatSubmittedCount = 0;
        if (Schema::hasTable('tax_periods')) {
            $vatSubmittedCount = TaxPeriod::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('type', TaxPeriodType::VAT->value)
                ->whereYear('period_start', $year)
                ->where('status', 'submitted')
                ->count();
        }

        $documents = [
            ['key' => 'invoices', 'label' => 'Invoices', 'count' => $invoiceCount, 'total' => $invoiceTotal, 'warning' => null],
            ['key' => 'expense_receipts', 'label' => 'Expense receipts', 'count' => $expenseReceiptsCount, 'total' => $expenseTotal, 'warning' => $expensesMissingReceipts > 0 ? "{$expensesMissingReceipts} missing receipts" : null],
            ['key' => 'vat_returns', 'label' => 'VAT returns', 'count' => $vatSubmittedCount, 'total' => 0, 'warning' => null],
            ['key' => 'contracts', 'label' => 'Contracts', 'count' => 0, 'total' => 0, 'warning' => $invoiceCount > 0 ? 'No contract registry yet' : null],
            ['key' => 'bank_statements', 'label' => 'Bank statements', 'count' => 0, 'total' => 0, 'warning' => 'Upload flow pending'],
            ['key' => 'sars_correspondence', 'label' => 'SARS correspondence', 'count' => 0, 'total' => 0, 'warning' => null],
        ];

        return Inertia::render('Tax/Documents/Index', [
            'selected_year' => $year,
            'available_years' => range($year - 3, $year + 1),
            'tax_year_summary' => [
                'income_statement_ready' => $invoiceCount > 0 || $expenseTotal > 0,
                'trial_balance_ready' => Schema::hasTable('journal_entries'),
                'vat_summary_ready' => $vatSubmittedCount > 0,
            ],
            'document_categories' => $documents,
            'checklist' => [
                'expenses_without_receipts' => $expensesMissingReceipts,
                'invoices_without_contracts' => $invoiceCount,
                'bank_statements_missing_months' => 12,
            ],
        ]);
    }
}
