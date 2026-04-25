<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        if (! Schema::hasTable('invoices')) {
            return Inertia::render('Invoicing/Invoices/Index', [
                'invoices' => new LengthAwarePaginator([], 0, 15),
                'summary' => [
                    'draft_count' => 0,
                    'sent_count' => 0,
                    'overdue_count' => 0,
                    'overdue_total' => 0,
                ],
                'filters' => $this->activeFilters($request),
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;
        $today = now()->toDateString();

        $query = Invoice::queryWithoutTeamScope()
            ->with('client:id,name')
            ->where('team_id', $teamId);

        $status = (string) $request->string('status')->toString();
        $from = (string) $request->string('from')->toString();
        $to = (string) $request->string('to')->toString();
        $client = trim((string) $request->string('client')->toString());
        $min = (int) $request->integer('min_amount');
        $max = (int) $request->integer('max_amount');

        if ($status !== '' && $status !== 'all') {
            if ($status === 'overdue') {
                $query->whereDate('due_date', '<', $today)
                    ->whereNotIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Void->value]);
            } else {
                $query->where('status', $status);
            }
        }

        if ($from !== '') {
            $query->whereDate('issue_date', '>=', $from);
        }

        if ($to !== '') {
            $query->whereDate('issue_date', '<=', $to);
        }

        if ($client !== '') {
            $query->whereHas('client', fn ($q) => $q->where('name', 'like', '%'.$client.'%'));
        }

        if ($min > 0) {
            $query->where('total_cents', '>=', $min * 100);
        }

        if ($max > 0) {
            $query->where('total_cents', '<=', $max * 100);
        }

        $invoices = $query
            ->orderByDesc('issue_date')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Invoice $invoice) use ($today): array {
                $total = (int) $invoice->getRawOriginal('total_cents');
                $paid = (int) $invoice->getRawOriginal('amount_paid_cents');
                $amountDue = max(0, $total - $paid);
                $isOverdue = Carbon::parse($invoice->due_date)->isPast()
                    && ! in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Void], true)
                    && $amountDue > 0;

                return [
                    'id' => $invoice->id,
                    'client_name' => $invoice->client?->name ?? 'Unknown',
                    'number' => $invoice->number,
                    'issue_date' => optional($invoice->issue_date)->toDateString(),
                    'due_date' => optional($invoice->due_date)->toDateString(),
                    'total' => $total,
                    'amount_due' => $amountDue,
                    'status' => $invoice->status->value,
                    'is_overdue' => $isOverdue,
                    'days_overdue' => $isOverdue
                        ? abs(Carbon::parse($invoice->due_date)->diffInDays(Carbon::parse($today)))
                        : 0,
                ];
            });

        $base = Invoice::queryWithoutTeamScope()->where('team_id', $teamId);
        $overdueRows = $base
            ->whereDate('due_date', '<', $today)
            ->whereNotIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Void->value])
            ->get();

        $overdueTotal = $overdueRows->sum(function (Invoice $invoice): int {
            $total = (int) $invoice->getRawOriginal('total_cents');
            $paid = (int) $invoice->getRawOriginal('amount_paid_cents');
            return max(0, $total - $paid);
        });

        return Inertia::render('Invoicing/Invoices/Index', [
            'invoices' => $invoices,
            'summary' => [
                'draft_count' => (clone $base)->where('status', InvoiceStatus::Draft->value)->count(),
                'sent_count' => (clone $base)->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Viewed->value])->count(),
                'overdue_count' => $overdueRows->count(),
                'overdue_total' => $overdueTotal,
            ],
            'filters' => $this->activeFilters($request),
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        abort_unless($invoice->team_id === request()->user()->current_team_id, 403);

        return Inertia::render('Invoicing/Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activeFilters(Request $request): array
    {
        return [
            'status' => $request->string('status')->toString() ?: 'all',
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'client' => $request->string('client')->toString() ?: null,
            'min_amount' => $request->integer('min_amount') ?: null,
            'max_amount' => $request->integer('max_amount') ?: null,
        ];
    }
}
