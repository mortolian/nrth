<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Invoicing\Actions\CreateInvoiceAction;
use App\Domain\Invoicing\Actions\RecordPaymentAction;
use App\Domain\Invoicing\Actions\SendInvoiceAction;
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
use App\Domain\Invoicing\Services\InvoiceNumberService;
use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
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

        $invoice->loadMissing(['lineItems', 'payments']);
        $amountPaid = (int) $invoice->getRawOriginal('amount_paid_cents');
        $amountDue = max(0, (int) $invoice->getRawOriginal('total_cents') - $amountPaid);

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
                    'vat_rate' => (float) $item->vat_rate,
                ])->values()->all(),
            ],
            ...$this->formMeta($request),
        ]);
    }

    public function store(Request $request, CreateInvoiceAction $createInvoiceAction, SendInvoiceAction $sendInvoiceAction): RedirectResponse
    {
        $payload = $this->validateInvoice($request, null);
        $teamId = (int) $request->user()->current_team_id;

        $invoice = $createInvoiceAction->execute(new CreateInvoiceDTO(
            teamId: $teamId,
            clientId: (int) $payload['client_id'],
            issueDate: (string) $payload['issue_date'],
            dueDate: (string) $payload['due_date'],
            currency: 'ZAR',
            reference: $payload['reference'] ?? null,
            notes: $payload['notes'] ?? null,
            footer: $payload['footer'] ?? null,
            lineItems: $payload['line_items'],
        ));

        if (($payload['number'] ?? null) !== null && $payload['number'] !== $invoice->number) {
            $invoice->update(['number' => (string) $payload['number']]);
        }

        if (($payload['submit_action'] ?? 'draft') === 'send') {
            $sendInvoiceAction->execute($invoice);
        }

        return to_route('invoicing.invoices.index');
    }

    public function update(Request $request, Invoice $invoice, SendInvoiceAction $sendInvoiceAction): RedirectResponse
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);

        $payload = $this->validateInvoice($request, $invoice);

        DB::transaction(function () use ($invoice, $payload): void {
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
            ]);
            $invoice->save();

            $invoice->lineItems()->delete();

            $subtotalCents = 0;
            $vatAmountCents = 0;

            foreach ($payload['line_items'] as $index => $line) {
                $quantity = (float) $line['quantity'];
                $unitPriceCents = (int) $line['unit_price_cents'];
                $vatRate = isset($line['vat_rate']) ? (float) $line['vat_rate'] : 0.0;

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
        });

        if (($payload['submit_action'] ?? 'draft') === 'send') {
            $sendInvoiceAction->execute($invoice->fresh());
        }

        return to_route('invoicing.invoices.index');
    }

    public function send(Request $request, Invoice $invoice, SendInvoiceAction $sendInvoiceAction): RedirectResponse
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);
        $sendInvoiceAction->execute($invoice);

        return to_route('invoicing.invoices.index');
    }

    public function void(Request $request, Invoice $invoice, VoidInvoiceAction $voidInvoiceAction): RedirectResponse
    {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);
        $voidInvoiceAction->execute($invoice, 'Voided from invoice UI');

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

        $invoice->loadMissing(['client', 'lineItems', 'payments.transaction']);

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

        return Inertia::render('Invoicing/Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'reference' => $invoice->reference,
                'issue_date' => optional($invoice->issue_date)->toDateString(),
                'due_date' => optional($invoice->due_date)->toDateString(),
                'notes' => $invoice->notes,
                'footer' => $invoice->footer,
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
                    fn (Payment $payment) => [
                        'id' => $payment->id,
                        'amount_cents' => (int) $payment->getRawOriginal('amount_cents'),
                        'payment_date' => optional($payment->payment_date)->toDateString(),
                        'method' => $payment->method->value,
                        'reference' => $payment->reference,
                        'notes' => $payment->notes,
                    ]
                )->all(),
                'activity_log' => $activities->map(fn (Activity $activity) => [
                    'id' => $activity->id,
                    'description' => $activity->description,
                    'event' => $activity->event,
                    'created_at' => optional($activity->created_at)?->toIso8601String(),
                ])->values()->all(),
                'attachments' => $invoice->pdfs()->map(fn ($media) => [
                    'id' => $media->id,
                    'name' => $media->file_name,
                    'url' => route('invoices.pdf.download', $invoice),
                ])->values()->all(),
            ],
            'can' => [
                'edit' => in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Sent, InvoiceStatus::Partial], true),
                'send' => in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Sent, InvoiceStatus::Partial], true),
                'void' => in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Sent], true),
                'record_payment' => in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Partial, InvoiceStatus::Overdue], true),
            ],
            'payment_methods' => array_map(
                fn (PaymentMethod $method) => [
                    'value' => $method->value,
                    'label' => match ($method) {
                        PaymentMethod::Eft => 'EFT',
                        PaymentMethod::Cash => 'Cash',
                        PaymentMethod::Card => 'Card',
                        PaymentMethod::Other => 'Other',
                    },
                ],
                PaymentMethod::cases()
            ),
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
        ]);

        $recordPaymentAction->execute(new RecordPaymentDTO(
            invoiceId: $invoice->id,
            teamId: (int) $request->user()->current_team_id,
            amountCents: (int) $payload['amount_cents'],
            paymentDate: (string) $payload['payment_date'],
            method: PaymentMethod::from((string) $payload['method']),
            currency: 'ZAR',
            reference: $payload['reference'] ?? null,
            notes: $payload['notes'] ?? null,
            createdBy: (int) $request->user()->id,
        ));

        return to_route('invoicing.invoices.show', $invoice);
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

    /**
     * @return array<string, mixed>
     */
    private function formMeta(Request $request): array
    {
        $teamId = (int) $request->user()->current_team_id;
        $team = $request->user()->currentTeam;
        $settings = $team->mergedCompanySettings();
        $year = (int) now()->format('Y');
        $next = InvoiceNumberSequence::query()
            ->where('team_id', $teamId)
            ->where('year', $year)
            ->first();

        $numberService = app(InvoiceNumberService::class);
        $nextNumber = $numberService->formatNumber($teamId, $year, $next?->next_number ?? 1);

        return [
            'clients' => Client::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'payment_terms_days'])
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'payment_terms_days' => (int) $client->payment_terms_days,
                ])
                ->all(),
            'tax_rates' => TaxRate::queryWithoutTeamScope()
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
                ->all(),
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
            'notes' => ['nullable', 'string'],
            'footer' => ['nullable', 'string'],
            'submit_action' => ['nullable', Rule::in(['draft', 'send'])],
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
