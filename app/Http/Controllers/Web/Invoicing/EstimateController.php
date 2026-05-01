<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Invoicing\Actions\MarkEstimateSentAction;
use App\Domain\Invoicing\Actions\SendEstimateAction;
use App\Domain\Invoicing\Enums\EstimateStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Estimate;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoiceCompanyCurrencySnapshot;
use App\Domain\Invoicing\Services\InvoiceNumberService;
use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Iso4217Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EstimateController extends Controller
{
    public function index(Request $request): Response
    {
        $teamId = (int) $request->user()->current_team_id;
        $status = (string) $request->string('status')->toString();
        $search = trim((string) $request->string('search')->toString());

        $query = Estimate::queryWithoutTeamScope()
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

        $estimates = $query->orderByDesc('issue_date')->get();

        return Inertia::render('Invoicing/Estimates/Index', [
            'estimates' => $estimates->map(fn (Estimate $estimate) => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'client_name' => $estimate->client?->name ?? 'Unknown',
                'issue_date' => optional($estimate->issue_date)->toDateString(),
                'expiry_date' => optional($estimate->expiry_date)->toDateString(),
                'total_cents' => (int) $estimate->getRawOriginal('total_cents'),
                'currency' => Iso4217Currencies::normalize((string) ($estimate->currency ?? 'ZAR')),
                'status' => $estimate->status->value,
                'converted_invoice_id' => $estimate->converted_invoice_id,
            ])->values()->all(),
            'summary' => [
                'draft' => $estimates->filter(fn (Estimate $e) => $e->status === EstimateStatus::Draft)->count(),
                'sent' => $estimates->filter(fn (Estimate $e) => $e->status === EstimateStatus::Sent)->count(),
                'accepted' => $estimates->filter(fn (Estimate $e) => $e->status === EstimateStatus::Accepted)->count(),
                'expired' => $estimates->filter(fn (Estimate $e) => $e->status === EstimateStatus::Expired)->count(),
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

        $chargesVat = $request->user()->currentTeam?->chargesVat() ?? false;
        $settings = $request->user()->currentTeam?->mergedCompanySettings() ?? [];

        return Inertia::render('Invoicing/Estimates/Form', [
            'isEditing' => false,
            'estimate' => null,
            'default_notes' => (string) ($settings['estimate_default_notes'] ?? ''),
            'default_terms' => (string) ($settings['estimate_default_terms'] ?? ''),
            'clients' => Client::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'currency'])
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'currency' => Iso4217Currencies::normalize((string) ($client->currency ?? 'ZAR')),
                ])
                ->values()
                ->all(),
            'default_currency' => Iso4217Currencies::normalize((string) ($settings['invoice_default_currency'] ?? 'ZAR')),
            'tax_rates' => $this->taxRatesForEstimateForm($teamId, $chargesVat),
            'charges_vat' => $chargesVat,
            'next_number' => $this->nextEstimateNumber($teamId),
        ]);
    }

    public function edit(Request $request, Estimate $estimate): Response
    {
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $teamId = (int) $request->user()->current_team_id;
        $chargesVat = $request->user()->currentTeam?->chargesVat() ?? false;
        $settings = $request->user()->currentTeam?->mergedCompanySettings() ?? [];

        return Inertia::render('Invoicing/Estimates/Form', [
            'isEditing' => true,
            'estimate' => $this->serializeEstimate($estimate->loadMissing('client'), $chargesVat),
            'default_notes' => (string) ($settings['estimate_default_notes'] ?? ''),
            'default_terms' => (string) ($settings['estimate_default_terms'] ?? ''),
            'clients' => Client::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'currency'])
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'currency' => Iso4217Currencies::normalize((string) ($client->currency ?? 'ZAR')),
                ])
                ->values()
                ->all(),
            'default_currency' => Iso4217Currencies::normalize((string) ($settings['invoice_default_currency'] ?? 'ZAR')),
            'tax_rates' => $this->taxRatesForEstimateForm($teamId, $chargesVat),
            'charges_vat' => $chargesVat,
            'next_number' => $this->nextEstimateNumber($teamId),
        ]);
    }

    public function show(Request $request, Estimate $estimate): Response
    {
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);

        return Inertia::render('Invoicing/Estimates/Show', [
            'estimate' => $this->serializeEstimate($estimate->loadMissing('client')),
            'can' => [
                'delete' => true,
            ],
            'charges_vat' => $request->user()->currentTeam?->chargesVat() ?? false,
            'convert_defaults' => [
                'invoice_due_date' => now()->addDays(30)->toDateString(),
                'invoice_footer' => (string) ($estimate->terms ?? ''),
                'invoice_notes' => (string) ($estimate->notes ?? ''),
            ],
        ]);
    }

    public function store(Request $request, SendEstimateAction $sendEstimateAction): RedirectResponse
    {
        $payload = $this->validateEstimate($request, null);
        $teamId = (int) $request->user()->current_team_id;
        $chargesVat = $request->user()->currentTeam?->chargesVat() ?? false;
        $lineItems = $this->normalizeEstimateLineItemsVat($payload['line_items'], $chargesVat);
        [$subtotal, $vat, $total] = $this->calculateTotals($lineItems);

        $submitAction = (string) ($payload['submit_action'] ?? 'draft');
        $estimate = Estimate::query()->create([
            'team_id' => $teamId,
            'client_id' => (int) $payload['client_id'],
            'status' => EstimateStatus::Draft,
            'number' => (string) $payload['number'],
            'issue_date' => (string) $payload['issue_date'],
            'expiry_date' => (string) $payload['expiry_date'],
            'subtotal_cents' => $subtotal,
            'vat_amount_cents' => $vat,
            'total_cents' => $total,
            'currency' => Iso4217Currencies::normalize((string) $payload['currency']),
            'line_items' => $lineItems,
            'notes' => $payload['notes'] ?? null,
            'terms' => $payload['terms'] ?? null,
            'sent_at' => null,
        ]);

        if ($submitAction === 'send') {
            $sendEstimateAction->execute($estimate);
        }

        return to_route('invoicing.estimates.show', $estimate);
    }

    public function update(Request $request, Estimate $estimate, SendEstimateAction $sendEstimateAction): RedirectResponse
    {
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $payload = $this->validateEstimate($request, $estimate);
        $chargesVat = $request->user()->currentTeam?->chargesVat() ?? false;
        $lineItems = $this->normalizeEstimateLineItemsVat($payload['line_items'], $chargesVat);
        [$subtotal, $vat, $total] = $this->calculateTotals($lineItems);

        $estimate->update([
            'client_id' => (int) $payload['client_id'],
            'number' => (string) $payload['number'],
            'issue_date' => (string) $payload['issue_date'],
            'expiry_date' => (string) $payload['expiry_date'],
            'subtotal_cents' => $subtotal,
            'vat_amount_cents' => $vat,
            'total_cents' => $total,
            'currency' => Iso4217Currencies::normalize((string) $payload['currency']),
            'line_items' => $lineItems,
            'notes' => $payload['notes'] ?? null,
            'terms' => $payload['terms'] ?? null,
        ]);

        if (($payload['submit_action'] ?? 'draft') === 'send' && $estimate->status === EstimateStatus::Draft) {
            $sendEstimateAction->execute($estimate->fresh());
        }

        return to_route('invoicing.estimates.show', $estimate);
    }

    public function send(Request $request, Estimate $estimate, SendEstimateAction $sendEstimateAction): RedirectResponse
    {
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $sendEstimateAction->execute($estimate);

        return back();
    }

    public function markSent(Request $request, Estimate $estimate, MarkEstimateSentAction $markEstimateSentAction): RedirectResponse
    {
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $markEstimateSentAction->execute($estimate);

        return back();
    }

    public function accept(Request $request, Estimate $estimate): RedirectResponse
    {
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $estimate->update(['status' => EstimateStatus::Accepted, 'accepted_at' => now(), 'declined_at' => null]);

        return back();
    }

    public function decline(Request $request, Estimate $estimate): RedirectResponse
    {
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $estimate->update(['status' => EstimateStatus::Declined, 'declined_at' => now(), 'accepted_at' => null]);

        return back();
    }

    public function convert(Request $request, Estimate $estimate, InvoiceNumberService $invoiceNumberService): RedirectResponse
    {
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        abort_if($estimate->converted_invoice_id !== null, 422, 'Estimate already converted.');
        $payload = $request->validate([
            'invoice_due_date' => ['required', 'date'],
            'invoice_footer' => ['nullable', 'string'],
            'invoice_notes' => ['nullable', 'string'],
        ]);

        $invoice = DB::transaction(function () use ($estimate, $invoiceNumberService, $payload): Invoice {
            $team = Team::query()->findOrFail((int) $estimate->team_id);
            $chargesVat = $team->chargesVat();
            $defaultVatRate = $team->defaultVatRateForInvoicing();

            $invoice = Invoice::query()->create([
                'team_id' => $estimate->team_id,
                'client_id' => $estimate->client_id,
                'status' => 'draft',
                'number' => $invoiceNumberService->generate((int) $estimate->team_id),
                'reference' => 'Converted from '.$estimate->number,
                'issue_date' => now()->toDateString(),
                'due_date' => (string) $payload['invoice_due_date'],
                'subtotal_cents' => 0,
                'vat_amount_cents' => 0,
                'total_cents' => 0,
                'amount_paid_cents' => 0,
                'currency' => $estimate->currency ?? 'ZAR',
                'notes' => $payload['invoice_notes'] ?? null,
                'footer' => $payload['invoice_footer'] ?? null,
            ]);

            $subtotalCents = 0;
            $vatCents = 0;

            foreach ((array) $estimate->line_items as $index => $line) {
                $quantity = (float) ($line['quantity'] ?? 1);
                $unitPriceCents = (int) ($line['unit_price_cents'] ?? 0);
                $vatRate = $chargesVat
                    ? (float) ($line['vat_rate'] ?? $defaultVatRate)
                    : 0.0;
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

                $subtotalCents += $lineSubtotal;
                $vatCents += $lineVat;
            }

            $invoice->update([
                'subtotal_cents' => $subtotalCents,
                'vat_amount_cents' => $vatCents,
                'total_cents' => $subtotalCents + $vatCents,
            ]);
            $invoice->refresh();
            app(InvoiceCompanyCurrencySnapshot::class)->sync($invoice);

            $estimate->update([
                'status' => EstimateStatus::Converted,
                'converted_invoice_id' => $invoice->id,
            ]);

            return $invoice->fresh();
        });

        return to_route('invoicing.invoices.show', $invoice);
    }

    public function destroy(Request $request, Estimate $estimate): RedirectResponse
    {
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);

        DB::transaction(function () use ($estimate): void {
            $estimate->clearMediaCollection('estimate-pdfs');
            $estimate->delete();
        });

        return to_route('invoicing.estimates.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEstimate(Request $request, ?Estimate $estimate): array
    {
        $teamId = (int) $request->user()->current_team_id;

        return $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('team_id', $teamId)],
            'number' => [
                'required',
                'string',
                'max:32',
                Rule::unique('estimates', 'number')
                    ->where(fn ($q) => $q->where('team_id', $teamId))
                    ->ignore($estimate?->id),
            ],
            'issue_date' => ['required', 'date'],
            'expiry_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
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
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEstimateLineItemsVat(array $lineItems, bool $chargesVat): array
    {
        return collect($lineItems)
            ->map(function (array $line) use ($chargesVat): array {
                $line['vat_rate'] = $chargesVat ? (float) ($line['vat_rate'] ?? 0) : 0.0;

                return $line;
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, rate: float, is_default: bool}>
     */
    private function taxRatesForEstimateForm(int $teamId, bool $chargesVat): array
    {
        if (! $chargesVat) {
            return [];
        }

        return TaxRate::queryWithoutTeamScope()
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
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEstimate(Estimate $estimate, bool $teamChargesVat = true): array
    {
        $lines = collect($estimate->line_items ?? [])->map(function ($line) use ($teamChargesVat) {
            $row = is_array($line) ? $line : (array) $line;
            if (! $teamChargesVat) {
                $row['vat_rate'] = 0.0;
            }

            return $row;
        })->values()->all();

        return [
            'id' => $estimate->id,
            'number' => $estimate->number,
            'client_id' => $estimate->client_id,
            'client_name' => $estimate->client?->name ?? 'Unknown',
            'issue_date' => optional($estimate->issue_date)->toDateString(),
            'expiry_date' => optional($estimate->expiry_date)->toDateString(),
            'total_cents' => (int) $estimate->getRawOriginal('total_cents'),
            'subtotal_cents' => (int) $estimate->getRawOriginal('subtotal_cents'),
            'vat_amount_cents' => (int) $estimate->getRawOriginal('vat_amount_cents'),
            'status' => $estimate->status->value,
            'line_items' => $lines,
            'notes' => $estimate->notes,
            'terms' => $estimate->terms,
            'currency' => Iso4217Currencies::normalize((string) ($estimate->currency ?? 'ZAR')),
            'converted_invoice_id' => $estimate->converted_invoice_id,
        ];
    }

    private function nextEstimateNumber(int $teamId): string
    {
        $settings = $this->teamSettings($teamId);
        $year = (int) now()->format('Y');
        $count = Estimate::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereYear('issue_date', $year)
            ->count() + 1;

        $prefixRaw = (string) ($settings['estimate_prefix'] ?? 'EST');
        $prefix = trim($prefixRaw, " \t\n\r\0\x0B-");
        if ($prefix === '') {
            $prefix = 'EST';
        }

        $parts = [$prefix, (string) $year];
        if ((bool) ($settings['estimate_number_include_month'] ?? false)) {
            $parts[] = now()->format('m');
        }

        if ((bool) ($settings['estimate_number_use_random_suffix'] ?? false)) {
            $parts[] = $this->randomIdentifier();
        } else {
            $parts[] = sprintf('%04d', $count);
        }

        return implode('-', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function teamSettings(int $teamId): array
    {
        $team = Team::query()->find($teamId);

        return $team?->mergedCompanySettings() ?? [];
    }

    private function randomIdentifier(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $suffix = '';
        for ($i = 0; $i < 4; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $suffix;
    }
}
