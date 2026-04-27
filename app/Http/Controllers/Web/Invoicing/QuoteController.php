<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Invoicing\Actions\SendQuoteAction;
use App\Domain\Invoicing\Enums\QuoteStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Quote;
use App\Domain\Invoicing\Services\InvoiceNumberService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    public function index(Request $request): Response
    {
        $teamId = (int) $request->user()->current_team_id;
        $status = (string) $request->string('status')->toString();
        $search = trim((string) $request->string('search')->toString());

        $query = Quote::queryWithoutTeamScope()
            ->with('client:id,name')
            ->where('team_id', $teamId);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('number', 'like', '%'.$search.'%')
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', '%'.$search.'%'));
            });
        }

        $quotes = $query->orderByDesc('issue_date')->get();

        return Inertia::render('Invoicing/Quotes/Index', [
            'quotes' => $quotes->map(fn (Quote $quote) => [
                'id' => $quote->id,
                'number' => $quote->number,
                'client_name' => $quote->client?->name ?? 'Unknown',
                'issue_date' => optional($quote->issue_date)->toDateString(),
                'expiry_date' => optional($quote->expiry_date)->toDateString(),
                'total_cents' => (int) $quote->getRawOriginal('total_cents'),
                'status' => $quote->status->value,
            ])->values()->all(),
            'summary' => [
                'draft' => $quotes->filter(fn (Quote $q) => $q->status === QuoteStatus::Draft)->count(),
                'sent' => $quotes->filter(fn (Quote $q) => $q->status === QuoteStatus::Sent)->count(),
                'accepted' => $quotes->filter(fn (Quote $q) => $q->status === QuoteStatus::Accepted)->count(),
                'expired' => $quotes->filter(fn (Quote $q) => $q->status === QuoteStatus::Expired)->count(),
            ],
            'filters' => [
                'status' => $status !== '' ? $status : 'all',
                'search' => $search !== '' ? $search : null,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $teamId = (int) $request->user()->current_team_id;

        return Inertia::render('Invoicing/Quotes/Form', [
            'isEditing' => false,
            'quote' => null,
            'clients' => Client::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name])
                ->values()
                ->all(),
            'next_number' => $this->nextQuoteNumber($teamId),
        ]);
    }

    public function edit(Request $request, Quote $quote): Response
    {
        abort_unless($quote->team_id === (int) $request->user()->current_team_id, 403);
        $teamId = (int) $request->user()->current_team_id;

        return Inertia::render('Invoicing/Quotes/Form', [
            'isEditing' => true,
            'quote' => $this->serializeQuote($quote->loadMissing('client')),
            'clients' => Client::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name])
                ->values()
                ->all(),
            'next_number' => $this->nextQuoteNumber($teamId),
        ]);
    }

    public function show(Request $request, Quote $quote): Response
    {
        abort_unless($quote->team_id === (int) $request->user()->current_team_id, 403);

        return Inertia::render('Invoicing/Quotes/Show', [
            'quote' => $this->serializeQuote($quote->loadMissing('client')),
            'convert_defaults' => [
                'invoice_due_date' => now()->addDays(30)->toDateString(),
                'invoice_footer' => (string) ($quote->terms ?? ''),
                'invoice_notes' => (string) ($quote->notes ?? ''),
            ],
        ]);
    }

    public function store(Request $request, SendQuoteAction $sendQuoteAction): RedirectResponse
    {
        $payload = $this->validateQuote($request, null);
        $teamId = (int) $request->user()->current_team_id;
        [$subtotal, $vat, $total] = $this->calculateTotals($payload['line_items']);

        $submitAction = (string) ($payload['submit_action'] ?? 'draft');
        $quote = Quote::query()->create([
            'team_id' => $teamId,
            'client_id' => (int) $payload['client_id'],
            'status' => QuoteStatus::Draft,
            'number' => (string) $payload['number'],
            'issue_date' => (string) $payload['issue_date'],
            'expiry_date' => (string) $payload['expiry_date'],
            'subtotal_cents' => $subtotal,
            'vat_amount_cents' => $vat,
            'total_cents' => $total,
            'currency' => 'ZAR',
            'line_items' => $payload['line_items'],
            'notes' => $payload['notes'] ?? null,
            'terms' => $payload['terms'] ?? null,
            'sent_at' => null,
        ]);

        if ($submitAction === 'send') {
            $sendQuoteAction->execute($quote);
        }

        return to_route('invoicing.quotes.show', $quote);
    }

    public function update(Request $request, Quote $quote, SendQuoteAction $sendQuoteAction): RedirectResponse
    {
        abort_unless($quote->team_id === (int) $request->user()->current_team_id, 403);
        $payload = $this->validateQuote($request, $quote);
        [$subtotal, $vat, $total] = $this->calculateTotals($payload['line_items']);

        $quote->update([
            'client_id' => (int) $payload['client_id'],
            'number' => (string) $payload['number'],
            'issue_date' => (string) $payload['issue_date'],
            'expiry_date' => (string) $payload['expiry_date'],
            'subtotal_cents' => $subtotal,
            'vat_amount_cents' => $vat,
            'total_cents' => $total,
            'line_items' => $payload['line_items'],
            'notes' => $payload['notes'] ?? null,
            'terms' => $payload['terms'] ?? null,
        ]);

        if (($payload['submit_action'] ?? 'draft') === 'send' && $quote->status === QuoteStatus::Draft) {
            $sendQuoteAction->execute($quote->fresh());
        }

        return to_route('invoicing.quotes.show', $quote);
    }

    public function send(Request $request, Quote $quote, SendQuoteAction $sendQuoteAction): RedirectResponse
    {
        abort_unless($quote->team_id === (int) $request->user()->current_team_id, 403);
        $sendQuoteAction->execute($quote);

        return back();
    }

    public function accept(Request $request, Quote $quote): RedirectResponse
    {
        abort_unless($quote->team_id === (int) $request->user()->current_team_id, 403);
        $quote->update(['status' => QuoteStatus::Accepted, 'accepted_at' => now(), 'declined_at' => null]);

        return back();
    }

    public function decline(Request $request, Quote $quote): RedirectResponse
    {
        abort_unless($quote->team_id === (int) $request->user()->current_team_id, 403);
        $quote->update(['status' => QuoteStatus::Declined, 'declined_at' => now(), 'accepted_at' => null]);

        return back();
    }

    public function convert(Request $request, Quote $quote, InvoiceNumberService $invoiceNumberService): RedirectResponse
    {
        abort_unless($quote->team_id === (int) $request->user()->current_team_id, 403);
        abort_if($quote->converted_invoice_id !== null, 422, 'Quote already converted.');
        $payload = $request->validate([
            'invoice_due_date' => ['required', 'date'],
            'invoice_footer' => ['nullable', 'string'],
            'invoice_notes' => ['nullable', 'string'],
        ]);

        $invoice = DB::transaction(function () use ($quote, $invoiceNumberService, $payload): Invoice {
            $invoice = Invoice::query()->create([
                'team_id' => $quote->team_id,
                'client_id' => $quote->client_id,
                'status' => 'draft',
                'number' => $invoiceNumberService->generate((int) $quote->team_id),
                'reference' => 'Converted from '.$quote->number,
                'issue_date' => now()->toDateString(),
                'due_date' => (string) $payload['invoice_due_date'],
                'subtotal_cents' => (int) $quote->getRawOriginal('subtotal_cents'),
                'vat_amount_cents' => (int) $quote->getRawOriginal('vat_amount_cents'),
                'total_cents' => (int) $quote->getRawOriginal('total_cents'),
                'amount_paid_cents' => 0,
                'currency' => $quote->currency ?? 'ZAR',
                'notes' => $payload['invoice_notes'] ?? null,
                'footer' => $payload['invoice_footer'] ?? null,
            ]);

            foreach ((array) $quote->line_items as $index => $line) {
                $quantity = (float) ($line['quantity'] ?? 1);
                $unitPriceCents = (int) ($line['unit_price_cents'] ?? 0);
                $vatRate = (float) ($line['vat_rate'] ?? 0);
                $lineSubtotal = (int) round($quantity * $unitPriceCents);
                $lineVat = (int) round($lineSubtotal * $vatRate);

                $invoice->lineItems()->create([
                    'description' => (string) ($line['description'] ?? ''),
                    'quantity' => $quantity,
                    'unit_price_cents' => $unitPriceCents,
                    'vat_rate' => $vatRate,
                    'vat_amount_cents' => $lineVat,
                    'total_cents' => $lineSubtotal + $lineVat,
                    'sort_order' => $index,
                ]);
            }

            $quote->update([
                'status' => QuoteStatus::Converted,
                'converted_invoice_id' => $invoice->id,
            ]);

            return $invoice;
        });

        return to_route('invoicing.invoices.show', $invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateQuote(Request $request, ?Quote $quote): array
    {
        $teamId = (int) $request->user()->current_team_id;
        return $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('team_id', $teamId)],
            'number' => [
                'required',
                'string',
                'max:32',
                Rule::unique('quotes', 'number')
                    ->where(fn ($q) => $q->where('team_id', $teamId))
                    ->ignore($quote?->id),
            ],
            'issue_date' => ['required', 'date'],
            'expiry_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string'],
            'terms' => ['nullable', 'string'],
            'submit_action' => ['nullable', Rule::in(['draft', 'send'])],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string', 'max:65535'],
            'line_items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'line_items.*.unit_price_cents' => ['required', 'integer', 'min:0'],
            'line_items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array<int, int>
     */
    private function calculateTotals(array $lineItems): array
    {
        $subtotal = 0;
        $vat = 0;
        foreach ($lineItems as $line) {
            $quantity = (float) ($line['quantity'] ?? 0);
            $unitPriceCents = (int) ($line['unit_price_cents'] ?? 0);
            $vatRate = (float) ($line['vat_rate'] ?? 0);
            $lineSubtotal = (int) round($quantity * $unitPriceCents);
            $lineVat = (int) round($lineSubtotal * $vatRate);
            $subtotal += $lineSubtotal;
            $vat += $lineVat;
        }

        return [$subtotal, $vat, $subtotal + $vat];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeQuote(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'number' => $quote->number,
            'client_id' => $quote->client_id,
            'client_name' => $quote->client?->name ?? 'Unknown',
            'issue_date' => optional($quote->issue_date)->toDateString(),
            'expiry_date' => optional($quote->expiry_date)->toDateString(),
            'total_cents' => (int) $quote->getRawOriginal('total_cents'),
            'subtotal_cents' => (int) $quote->getRawOriginal('subtotal_cents'),
            'vat_amount_cents' => (int) $quote->getRawOriginal('vat_amount_cents'),
            'status' => $quote->status->value,
            'line_items' => $quote->line_items ?? [],
            'notes' => $quote->notes,
            'terms' => $quote->terms,
            'converted_invoice_id' => $quote->converted_invoice_id,
        ];
    }

    private function nextQuoteNumber(int $teamId): string
    {
        $year = (int) now()->format('Y');
        $count = Quote::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereYear('issue_date', $year)
            ->count() + 1;

        return sprintf('Q-%d-%04d', $year, $count);
    }
}

