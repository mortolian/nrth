<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\TransactionStatus;
use App\Domain\Accounting\Models\Account;
use App\Domain\Invoicing\Actions\CreateInvoiceAction;
use App\Domain\Invoicing\Actions\MarkInvoiceSentAction;
use App\Domain\Invoicing\Actions\RecordPaymentAction;
use App\Domain\Invoicing\Actions\SendInvoiceAction;
use App\Domain\Invoicing\Actions\UndoInvoicePaymentAction;
use App\Domain\Invoicing\Actions\UnvoidInvoiceAction;
use App\Domain\Invoicing\Actions\VoidInvoiceAction;
use App\Domain\Invoicing\DTOs\CreateInvoiceDTO;
use App\Domain\Invoicing\DTOs\RecordPaymentDTO;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLineItem;
use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\Services\InvoiceCompanyCurrencySnapshot;
use App\Domain\Invoicing\Services\InvoiceNumberService;
use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
use App\Support\Iso4217Currencies;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class InvoiceController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Invoicing/Invoices/Form', [
            'isEditing' => false,
            'invoice' => null,
            ...$this->formMeta($request),
        ]);
    }

    public function edit(Request $request, Invoice $invoice): Response
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);
        abort_if($invoice->status === InvoiceStatus::Void, 403);

        $invoice->loadMissing(['lineItems', 'payments']);
        $amountPaid = (int) $invoice->getRawOriginal('amount_paid_cents');
        $amountDue = max(0, (int) $invoice->getRawOriginal('total_cents') - $amountPaid);
        $chargesVat = $request->user()->currentTeam?->chargesVat() ?? false;

        return Inertia::render('Invoicing/Invoices/Form', [
            'isEditing' => true,
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'client_id' => $invoice->client_id,
                'reference' => $invoice->reference,
                'issue_date' => optional($invoice->issue_date)->toDateString(),
                'due_date' => optional($invoice->due_date)->toDateString(),
                'notes' => $invoice->notes,
                'footer' => $invoice->footer,
                'status' => $invoice->status->value,
                'subtotal_cents' => (int) $invoice->getRawOriginal('subtotal_cents'),
                'vat_amount_cents' => (int) $invoice->getRawOriginal('vat_amount_cents'),
                'total_cents' => (int) $invoice->getRawOriginal('total_cents'),
                'amount_paid_cents' => $amountPaid,
                'amount_due_cents' => $amountDue,
                'line_items' => $invoice->lineItems->map(fn (InvoiceLineItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => round(((int) $item->unit_price_cents) / 100, 2),
                    'vat_rate' => $chargesVat ? (float) $item->vat_rate : 0.0,
                ])->values()->all(),
                'currency' => Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR')),
                'company_currency_code' => $invoice->company_currency_code !== null
                    ? Iso4217Currencies::normalize((string) $invoice->company_currency_code)
                    : null,
                'fx_rate_invoice_to_company' => $invoice->fx_rate_invoice_to_company !== null
                    ? (string) $invoice->fx_rate_invoice_to_company
                    : null,
                'fx_rate_date' => optional($invoice->fx_rate_date)->toDateString(),
                'total_company_currency_cents' => $invoice->total_company_currency_cents !== null
                    ? (int) $invoice->getRawOriginal('total_company_currency_cents')
                    : null,
            ],
            ...$this->formMeta($request),
        ]);
    }

    public function store(Request $request, CreateInvoiceAction $createInvoiceAction): RedirectResponse
    {
        $payload = $this->validateInvoice($request, null);
        $teamId = (int) $request->user()->current_team_id;

        $invoice = $createInvoiceAction->execute(new CreateInvoiceDTO(
            teamId: $teamId,
            clientId: (int) $payload['client_id'],
            issueDate: (string) $payload['issue_date'],
            dueDate: (string) $payload['due_date'],
            currency: Iso4217Currencies::normalize((string) $payload['currency']),
            reference: $payload['reference'] ?? null,
            notes: $payload['notes'] ?? null,
            footer: $payload['footer'] ?? null,
            lineItems: $payload['line_items'],
        ));

        if (($payload['number'] ?? null) !== null && $payload['number'] !== $invoice->number) {
            $invoice->update(['number' => (string) $payload['number']]);
        }

        return to_route('invoicing.invoices.show', $invoice->fresh());
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);
        abort_if($invoice->status === InvoiceStatus::Void, 403);

        $payload = $this->validateInvoice($request, $invoice);

        $team = $request->user()->currentTeam;
        $chargesVat = $team?->chargesVat() ?? false;
        $defaultVatRate = $team?->defaultVatRateForInvoicing() ?? 0.0;

        DB::transaction(function () use ($invoice, $payload, $chargesVat, $defaultVatRate): void {
            $issueDate = Carbon::parse((string) $payload['issue_date']);
            $dueDate = Carbon::parse((string) $payload['due_date']);

            $invoice->fill([
                'client_id' => (int) $payload['client_id'],
                'number' => (string) ($payload['number'] ?? $invoice->number),
                'reference' => $payload['reference'] ?? null,
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'notes' => $payload['notes'] ?? null,
                'footer' => $payload['footer'] ?? null,
                'currency' => Iso4217Currencies::normalize((string) $payload['currency']),
            ]);
            $invoice->save();

            $invoice->lineItems()->delete();

            $subtotalCents = 0;
            $vatAmountCents = 0;

            foreach ($payload['line_items'] as $index => $line) {
                $quantity = (float) $line['quantity'];
                $unitPriceCents = (int) $line['unit_price_cents'];
                $vatRate = $chargesVat
                    ? (float) ($line['vat_rate'] ?? $defaultVatRate)
                    : 0.0;

                $lineSubtotal = (int) round($quantity * $unitPriceCents);
                $lineVat = (int) round($lineSubtotal * $vatRate);
                $lineTotal = $lineSubtotal + $lineVat;

                $invoice->lineItems()->create([
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
            $invoice->refresh();
            app(InvoiceCompanyCurrencySnapshot::class)->sync($invoice);
        });

        return to_route('invoicing.invoices.show', $invoice->fresh());
    }

    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);

        if ($invoice->payments()->exists()) {
            return to_route('invoicing.invoices.show', $invoice)
                ->withErrors([
                    'delete' => __('Invoices with recorded payments cannot be deleted.'),
                ]);
        }

        DB::transaction(function () use ($invoice): void {
            $invoice->clearMediaCollection('invoice-pdfs');
            $invoice->delete();
        });

        return to_route('invoicing.invoices.index');
    }

    public function send(Request $request, Invoice $invoice, SendInvoiceAction $sendInvoiceAction): RedirectResponse
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);
        $sendInvoiceAction->execute($invoice);

        return to_route('invoicing.invoices.show', $invoice->fresh());
    }

    public function markSent(Request $request, Invoice $invoice, MarkInvoiceSentAction $markInvoiceSentAction): RedirectResponse
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);
        $markInvoiceSentAction->execute($invoice);

        return to_route('invoicing.invoices.show', $invoice->fresh());
    }

    public function void(Request $request, Invoice $invoice, VoidInvoiceAction $voidInvoiceAction): RedirectResponse
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);
        $voidInvoiceAction->execute($invoice, 'Voided from invoice UI');

        return to_route('invoicing.invoices.index');
    }

    public function unvoid(Request $request, Invoice $invoice, UnvoidInvoiceAction $unvoidInvoiceAction): RedirectResponse
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);
        $unvoidInvoiceAction->execute($invoice);

        return to_route('invoicing.invoices.index');
    }

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
                    'overdue_totals_by_currency' => [],
                ],
                'filters' => $this->activeFilters($request),
                'filter_client' => null,
            ]);
        }

        $teamId = (int) $request->user()->current_team_id;
        $today = now()->toDateString();

        /** Past-due only applies once an invoice is out of draft (issued / awaiting payment). */
        $statusesWherePastDueMatters = [
            InvoiceStatus::Sent,
            InvoiceStatus::Viewed,
            InvoiceStatus::Partial,
            InvoiceStatus::Overdue,
        ];
        $statusValuesWherePastDueMatters = array_map(
            static fn (InvoiceStatus $s): string => $s->value,
            $statusesWherePastDueMatters
        );

        $query = Invoice::queryWithoutTeamScope()
            ->with('client:id,name')
            ->where('team_id', $teamId);

        $status = (string) $request->string('status')->toString();
        $from = (string) $request->string('from')->toString();
        $to = (string) $request->string('to')->toString();
        $client = trim((string) $request->string('client')->toString());
        $clientId = (int) $request->integer('client_id');
        $minCents = 0;
        $maxCents = 0;

        $minMajor = $request->input('min_amount');
        if ($minMajor !== null && $minMajor !== '') {
            $minCents = (int) round(((float) $minMajor) * 100);
        }

        $maxMajor = $request->input('max_amount');
        if ($maxMajor !== null && $maxMajor !== '') {
            $maxCents = (int) round(((float) $maxMajor) * 100);
        }

        $filterClientContext = null;
        if ($clientId > 0) {
            $filterClient = Client::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->whereKey($clientId)
                ->first(['id', 'name']);
            abort_if($filterClient === null, 404);
            $query->where('client_id', $clientId);
            $filterClientContext = [
                'id' => $filterClient->id,
                'name' => $filterClient->name,
            ];
        } elseif ($client !== '') {
            $query->whereHas('client', fn ($q) => $q->where('name', 'like', '%'.$client.'%'));
        }

        if ($status !== '' && $status !== 'all') {
            if ($status === 'overdue') {
                $query->whereDate('due_date', '<', $today)
                    ->whereIn('status', $statusValuesWherePastDueMatters);
            } elseif ($status === InvoiceStatus::Sent->value) {
                $query->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Viewed->value]);
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

        if ($minCents > 0) {
            $query->where('total_cents', '>=', $minCents);
        }

        if ($maxCents > 0) {
            $query->where('total_cents', '<=', $maxCents);
        }

        $invoices = $query
            ->withCount('payments')
            ->orderByDesc('issue_date')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Invoice $invoice) use ($today, $statusesWherePastDueMatters): array {
                $total = (int) $invoice->getRawOriginal('total_cents');
                $paid = (int) $invoice->getRawOriginal('amount_paid_cents');
                $amountDue = max(0, $total - $paid);
                $dueDate = optional($invoice->due_date)?->toDateString();
                $isOverdue = in_array($invoice->status, $statusesWherePastDueMatters, true)
                    && $dueDate !== null
                    && $dueDate < $today
                    && $amountDue > 0;

                return [
                    'id' => $invoice->id,
                    'client_name' => $invoice->client?->name ?? 'Unknown',
                    'number' => $invoice->number,
                    'issue_date' => optional($invoice->issue_date)->toDateString(),
                    'due_date' => optional($invoice->due_date)->toDateString(),
                    'currency' => Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR')),
                    'company_currency_code' => $invoice->company_currency_code !== null
                        ? Iso4217Currencies::normalize((string) $invoice->company_currency_code)
                        : null,
                    'fx_rate_invoice_to_company' => $invoice->fx_rate_invoice_to_company !== null
                        ? (string) $invoice->fx_rate_invoice_to_company
                        : null,
                    'fx_rate_date' => optional($invoice->fx_rate_date)->toDateString(),
                    'total_company_currency_cents' => $invoice->total_company_currency_cents !== null
                        ? (int) $invoice->getRawOriginal('total_company_currency_cents')
                        : null,
                    'total' => $total,
                    'amount_due' => $amountDue,
                    'status' => $invoice->status->value,
                    'is_overdue' => $isOverdue,
                    'days_overdue' => $isOverdue
                        ? abs(Carbon::parse($invoice->due_date)->diffInDays(Carbon::parse($today)))
                        : 0,
                    'can_delete' => (int) $invoice->payments_count === 0,
                ];
            });

        $base = Invoice::queryWithoutTeamScope()->where('team_id', $teamId);

        // Clone before overdue filters — otherwise $base is mutated and draft/sent counts inherit wrong constraints.
        $overdueRows = (clone $base)
            ->whereDate('due_date', '<', $today)
            ->whereIn('status', $statusValuesWherePastDueMatters)
            ->get();

        $overdueTotal = $overdueRows->sum(function (Invoice $invoice): int {
            $total = (int) $invoice->getRawOriginal('total_cents');
            $paid = (int) $invoice->getRawOriginal('amount_paid_cents');

            return max(0, $total - $paid);
        });

        /** @var array<string, int> */
        $overdueTotalsByCurrency = [];
        foreach ($overdueRows as $invoice) {
            $currency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));
            $total = (int) $invoice->getRawOriginal('total_cents');
            $paid = (int) $invoice->getRawOriginal('amount_paid_cents');
            $amountDue = max(0, $total - $paid);

            $overdueTotalsByCurrency[$currency] = ($overdueTotalsByCurrency[$currency] ?? 0) + $amountDue;
        }

        $overdueTotalsByCurrencyRows = collect($overdueTotalsByCurrency)
            ->map(fn (int $totalCents, string $currencyCode): array => [
                'currency' => $currencyCode,
                'total_cents' => $totalCents,
            ])
            ->sortByDesc('total_cents')
            ->values()
            ->all();

        return Inertia::render('Invoicing/Invoices/Index', [
            'invoices' => $invoices,
            'summary' => [
                'draft_count' => (clone $base)->where('status', InvoiceStatus::Draft->value)->count(),
                'sent_count' => (clone $base)->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::Viewed->value])->count(),
                'overdue_count' => $overdueRows->count(),
                'overdue_total' => $overdueTotal,
                'overdue_totals_by_currency' => $overdueTotalsByCurrencyRows,
            ],
            'filters' => $this->activeFilters($request),
            'filter_client' => $filterClientContext,
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        abort_unless($invoice->team_id === request()->user()->current_team_id, 403);

        $invoice->loadMissing(['team', 'client', 'lineItems', 'payments.transaction']);

        $totalCents = (int) $invoice->getRawOriginal('total_cents');
        $paidCents = (int) $invoice->getRawOriginal('amount_paid_cents');
        $amountDueCents = max(0, $totalCents - $paidCents);

        $activityTable = (string) config('activitylog.table_name', 'activity_log');
        $activities = collect();
        if (Schema::hasTable($activityTable)) {
            $activities = Activity::query()
                ->where('subject_type', Invoice::class)
                ->where('subject_id', $invoice->id)
                ->latest()
                ->limit(20)
                ->get();
        }

        $issuer = $invoice->team !== null
            ? $invoice->team->issuerForInvoicingDocuments()
            : [
                'name' => (string) config('app.name'),
                'address' => null,
                'email' => null,
                'phone' => null,
                'website' => null,
                'registration_number' => null,
                'vat_number' => null,
            ];

        $companyCurrency = Iso4217Currencies::normalize(
            (string) ($invoice->team?->mergedCompanySettings()['invoice_default_currency'] ?? 'ZAR')
        );

        return Inertia::render('Invoicing/Invoices/Show', [
            'issuer' => $issuer,
            'company_currency' => $companyCurrency,
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'reference' => $invoice->reference,
                'issue_date' => optional($invoice->issue_date)->toDateString(),
                'due_date' => optional($invoice->due_date)->toDateString(),
                'notes' => $invoice->notes,
                'footer' => $invoice->footer,
                'currency' => Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR')),
                'company_currency_code' => $invoice->company_currency_code !== null
                    ? Iso4217Currencies::normalize((string) $invoice->company_currency_code)
                    : null,
                'fx_rate_invoice_to_company' => $invoice->fx_rate_invoice_to_company !== null
                    ? (string) $invoice->fx_rate_invoice_to_company
                    : null,
                'fx_rate_date' => optional($invoice->fx_rate_date)->toDateString(),
                'total_company_currency_cents' => $invoice->total_company_currency_cents !== null
                    ? (int) $invoice->getRawOriginal('total_company_currency_cents')
                    : null,
                'subtotal_cents' => (int) $invoice->getRawOriginal('subtotal_cents'),
                'vat_amount_cents' => (int) $invoice->getRawOriginal('vat_amount_cents'),
                'total_cents' => $totalCents,
                'amount_paid_cents' => $paidCents,
                'amount_due_cents' => $amountDueCents,
                'sent_at' => optional($invoice->sent_at)?->toIso8601String(),
                'viewed_at' => optional($invoice->viewed_at)?->toIso8601String(),
                'paid_at' => optional($invoice->paid_at)?->toIso8601String(),
                'created_at' => optional($invoice->created_at)?->toIso8601String(),
                'client' => [
                    'id' => $invoice->client?->id,
                    'name' => $invoice->client?->name,
                    'email' => $invoice->client?->email,
                    'phone' => $invoice->client?->phone,
                ],
                'line_items' => $invoice->lineItems->map(fn (InvoiceLineItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price_cents' => (int) $item->unit_price_cents,
                    'vat_rate' => (float) $item->vat_rate,
                    'vat_amount_cents' => (int) $item->vat_amount_cents,
                    'total_cents' => (int) $item->total_cents,
                ])->values()->all(),
                'payments' => $invoice->payments->sortByDesc('payment_date')->values()->map(
                    function (Payment $payment): array {
                        $tx = $payment->transaction;

                        return [
                            'id' => $payment->id,
                            'amount_cents' => (int) $payment->getRawOriginal('amount_cents'),
                            'payment_date' => optional($payment->payment_date)->toDateString(),
                            'method' => $payment->method->value,
                            'reference' => $payment->reference,
                            'notes' => $payment->notes,
                            'can_undo' => $tx !== null && in_array($tx->status, [TransactionStatus::Posted, TransactionStatus::Draft], true),
                        ];
                    }
                )->all(),
                'activity_log' => $activities->map(fn (Activity $activity) => [
                    'id' => $activity->id,
                    'description' => $activity->description,
                    'event' => $activity->event,
                    'created_at' => optional($activity->created_at)?->toIso8601String(),
                ])->values()->all(),
            ],
            'can' => [
                'edit' => $invoice->status !== InvoiceStatus::Void,
                'send' => in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Sent, InvoiceStatus::Partial], true),
                'mark_sent' => $invoice->status === InvoiceStatus::Draft,
                'void' => $invoice->status === InvoiceStatus::Sent,
                'unvoid' => $invoice->status === InvoiceStatus::Void,
                'record_payment' => in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Partial, InvoiceStatus::Overdue], true),
                'delete' => ! $invoice->payments()->exists(),
            ],
            'online_payment_providers' => $this->onlinePaymentProvidersForInvoice($invoice),
            'charges_vat' => $invoice->team?->chargesVat() ?? false,
        ]);
    }

    public function recordPayment(Request $request, Invoice $invoice, RecordPaymentAction $recordPaymentAction): RedirectResponse
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);

        $payload = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $method) => $method->value, PaymentMethod::cases()))],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'bank_amount_company_cents' => ['nullable', 'integer', 'min:0'],
            'book_fx_loss_to_expense' => ['sometimes', 'boolean'],
        ]);

        $recordPaymentAction->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: (int) $request->user()->current_team_id,
            amountCents: (int) $payload['amount_cents'],
            paymentDate: (string) $payload['payment_date'],
            method: PaymentMethod::from((string) $payload['method']),
            currency: Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR')),
            reference: $payload['reference'] ?? null,
            notes: $payload['notes'] ?? null,
            createdBy: (int) $request->user()->id,
            bankAmountCompanyCents: isset($payload['bank_amount_company_cents'])
                ? (int) $payload['bank_amount_company_cents']
                : null,
            bookFxLossToExpense: (bool) ($payload['book_fx_loss_to_expense'] ?? false),
        ));

        return back();
    }

    public function undoPayment(Request $request, Invoice $invoice, Payment $payment, UndoInvoicePaymentAction $undoInvoicePaymentAction): RedirectResponse
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);
        abort_unless($payment->invoice_id === $invoice->id, 404);
        abort_unless($payment->team_id === $invoice->team_id, 403);

        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $undoInvoicePaymentAction->execute(
            $payment,
            (int) $request->user()->current_team_id,
            $request->filled('reason') ? $request->string('reason')->toString() : null,
        );

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function activeFilters(Request $request): array
    {
        $clientId = (int) $request->integer('client_id');

        return [
            'status' => $request->string('status')->toString() ?: 'all',
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'client' => $request->string('client')->toString() ?: null,
            'client_id' => $clientId > 0 ? $clientId : null,
            'min_amount' => $request->filled('min_amount') ? (float) $request->input('min_amount') : null,
            'max_amount' => $request->filled('max_amount') ? (float) $request->input('max_amount') : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formMeta(Request $request): array
    {
        $teamId = (int) $request->user()->current_team_id;
        $team = $request->user()->currentTeam;
        $settings = $team?->mergedCompanySettings() ?? [];
        if ($teamId <= 0 || $team === null) {
            return [
                'clients' => [],
                'tax_rates' => [],
                'accounts' => [],
                'charges_vat' => false,
                'next_number' => 'INV-'.now()->format('Y').'-0001',
                'default_currency' => 'ZAR',
                'defaults' => [
                    'payment_terms_days' => 30,
                    'notes' => '',
                    'footer' => '',
                ],
            ];
        }
        $year = (int) now()->format('Y');
        $next = InvoiceNumberSequence::query()
            ->where('team_id', $teamId)
            ->where('year', $year)
            ->first();

        $numberService = app(InvoiceNumberService::class);
        $nextNumber = $numberService->formatNumber($teamId, $year, $next?->next_number ?? 1, now());

        $chargesVat = $team->chargesVat();

        return [
            'clients' => Client::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'payment_terms_days', 'currency'])
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'payment_terms_days' => (int) $client->payment_terms_days,
                    'currency' => Iso4217Currencies::normalize((string) ($client->currency ?? 'ZAR')),
                ])
                ->all(),
            'default_currency' => Iso4217Currencies::normalize((string) ($settings['invoice_default_currency'] ?? 'ZAR')),
            'tax_rates' => $chargesVat
                ? TaxRate::queryWithoutTeamScope()
                    ->where('team_id', $teamId)
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->orderBy('name')
                    ->get(['id', 'name', 'rate', 'is_default'])
                    ->map(fn (TaxRate $taxRate) => [
                        'id' => $taxRate->id,
                        'name' => $taxRate->name,
                        'rate' => $taxRate->rate !== null ? (float) $taxRate->rate : 0.0,
                        'is_default' => (bool) $taxRate->is_default,
                    ])
                    ->all()
                : [],
            'charges_vat' => $chargesVat,
            'accounts' => Account::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('type', AccountType::Income->value)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'name' => trim($account->code.' - '.$account->name),
                ])
                ->all(),
            'next_number' => $nextNumber,
            'defaults' => [
                'payment_terms_days' => (int) ($settings['invoice_default_payment_terms_days'] ?? 30),
                'notes' => (string) ($settings['invoice_default_notes'] ?? ''),
                'footer' => (string) ($settings['invoice_default_footer'] ?? ''),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function onlinePaymentProvidersForInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('team');
        $team = $invoice->team;
        if ($team === null) {
            return [];
        }

        $settings = $team->mergedCompanySettings();
        /** @var array<string, mixed> $gateways */
        $gateways = is_array($settings['payment_gateways'] ?? null) ? $settings['payment_gateways'] : [];
        $currency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));

        $providers = [];

        /** @var array<string, mixed> $stripe */
        $stripe = is_array($gateways['stripe'] ?? null) ? $gateways['stripe'] : [];
        $stripeSecret = isset($stripe['secret_key']) && is_string($stripe['secret_key']) ? trim($stripe['secret_key']) : '';
        if (($stripe['enabled'] ?? false) && $stripeSecret !== '') {
            $providers[] = 'stripe';
        }

        /** @var array<string, mixed> $payfast */
        $payfast = is_array($gateways['payfast'] ?? null) ? $gateways['payfast'] : [];
        $mid = isset($payfast['merchant_id']) && is_string($payfast['merchant_id']) ? trim($payfast['merchant_id']) : '';
        $mkey = isset($payfast['merchant_key']) && is_string($payfast['merchant_key']) ? trim($payfast['merchant_key']) : '';
        if ($currency === 'ZAR' && ($payfast['enabled'] ?? false) && $mid !== '' && $mkey !== '') {
            $providers[] = 'payfast';
        }

        return $providers;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateInvoice(Request $request, ?Invoice $invoice): array
    {
        $teamId = (int) $request->user()->current_team_id;

        $validated = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('team_id', $teamId)],
            'number' => [
                'required',
                'string',
                'max:32',
                Rule::unique('invoices', 'number')
                    ->where(fn ($query) => $query->where('team_id', $teamId))
                    ->ignore($invoice?->id),
            ],
            'reference' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'notes' => ['nullable', 'string'],
            'footer' => ['nullable', 'string'],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string', 'max:65535'],
            'line_items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'line_items.*.unit_price_cents' => ['required', 'integer', 'min:0'],
            'line_items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        if (empty($validated['line_items'])) {
            throw ValidationException::withMessages(['line_items' => __('At least one line item is required.')]);
        }

        return $validated;
    }
}
