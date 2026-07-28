<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Invoicing\Actions\MarkEstimateSentAction;
use App\Domain\Invoicing\Actions\SendEstimateAction;
use App\Domain\Invoicing\Enums\EstimateStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Estimate;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Item;
use App\Domain\Invoicing\Services\InvoiceBusinessCurrencySnapshot;
use App\Domain\Invoicing\Services\InvoiceNumberService;
use App\Domain\Invoicing\Services\InvoiceTotalsCalculator;
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
        $this->authorizeTeam('estimates.view', $request);

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
        $this->authorizeTeam('estimates.manage', $request);

        $teamId = (int) $request->user()->current_team_id;

        $chargesVat = $request->user()->currentTeam?->chargesVat() ?? false;
        $settings = $request->user()->currentTeam?->mergedBusinessSettings() ?? [];

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
            'items' => $this->catalogItemsForForm($teamId),
            'default_currency' => Iso4217Currencies::normalize((string) ($settings['invoice_default_currency'] ?? 'ZAR')),
            'tax_rates' => $this->taxRatesForEstimateForm($teamId, $chargesVat),
            'charges_vat' => $chargesVat,
            'next_number' => $this->nextEstimateNumber($teamId),
        ]);
    }

    public function edit(Request $request, Estimate $estimate): Response
    {
        $this->authorizeTeam('estimates.manage', $request);
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $teamId = (int) $request->user()->current_team_id;
        $chargesVat = $request->user()->currentTeam?->chargesVat() ?? false;
        $settings = $request->user()->currentTeam?->mergedBusinessSettings() ?? [];

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
            'items' => $this->catalogItemsForForm($teamId),
            'default_currency' => Iso4217Currencies::normalize((string) ($settings['invoice_default_currency'] ?? 'ZAR')),
            'tax_rates' => $this->taxRatesForEstimateForm($teamId, $chargesVat),
            'charges_vat' => $chargesVat,
            'next_number' => $this->nextEstimateNumber($teamId),
        ]);
    }

    public function show(Request $request, Estimate $estimate): Response
    {
        $this->authorizeTeam('estimates.view', $request);
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
        $this->authorizeTeam('estimates.manage', $request);

        $payload = $this->validateEstimate($request, null);
        $teamId = (int) $request->user()->current_team_id;
        $chargesVat = $request->user()->currentTeam?->chargesVat() ?? false;
        $lineItems = $this->normalizeEstimateLineItemsVat($payload['line_items'], $chargesVat);
        [$lineItems, $subtotal, $vat, $total, $discountTotal] = $this->calculateTotalsWithDiscounts(
            $lineItems,
            $payload['discount_type'] ?? null,
            $payload['discount_percent'] ?? null,
            $payload['discount_cents'] ?? null,
        );

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
            'discount_type' => $payload['discount_type'] ?? null,
            'discount_percent' => ($payload['discount_type'] ?? null) === 'percent' ? ($payload['discount_percent'] ?? null) : null,
            'discount_cents' => ($payload['discount_type'] ?? null) === 'fixed' ? ($payload['discount_cents'] ?? null) : null,
            'discount_total_cents' => $discountTotal,
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
        $this->authorizeTeam('estimates.manage', $request);
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $payload = $this->validateEstimate($request, $estimate);
        $chargesVat = $request->user()->currentTeam?->chargesVat() ?? false;
        $lineItems = $this->normalizeEstimateLineItemsVat($payload['line_items'], $chargesVat);
        [$lineItems, $subtotal, $vat, $total, $discountTotal] = $this->calculateTotalsWithDiscounts(
            $lineItems,
            $payload['discount_type'] ?? null,
            $payload['discount_percent'] ?? null,
            $payload['discount_cents'] ?? null,
        );

        $estimate->update([
            'client_id' => (int) $payload['client_id'],
            'number' => (string) $payload['number'],
            'issue_date' => (string) $payload['issue_date'],
            'expiry_date' => (string) $payload['expiry_date'],
            'subtotal_cents' => $subtotal,
            'vat_amount_cents' => $vat,
            'total_cents' => $total,
            'discount_type' => $payload['discount_type'] ?? null,
            'discount_percent' => ($payload['discount_type'] ?? null) === 'percent' ? ($payload['discount_percent'] ?? null) : null,
            'discount_cents' => ($payload['discount_type'] ?? null) === 'fixed' ? ($payload['discount_cents'] ?? null) : null,
            'discount_total_cents' => $discountTotal,
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
        $this->authorizeTeam('estimates.manage', $request);
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $sendEstimateAction->execute($estimate);

        return back();
    }

    public function markSent(Request $request, Estimate $estimate, MarkEstimateSentAction $markEstimateSentAction): RedirectResponse
    {
        $this->authorizeTeam('estimates.manage', $request);
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $markEstimateSentAction->execute($estimate);

        return back();
    }

    public function accept(Request $request, Estimate $estimate): RedirectResponse
    {
        $this->authorizeTeam('estimates.manage', $request);
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $estimate->update(['status' => EstimateStatus::Accepted, 'accepted_at' => now(), 'declined_at' => null]);

        return back();
    }

    public function decline(Request $request, Estimate $estimate): RedirectResponse
    {
        $this->authorizeTeam('estimates.manage', $request);
        abort_unless($estimate->team_id === (int) $request->user()->current_team_id, 403);
        $estimate->update(['status' => EstimateStatus::Declined, 'declined_at' => now(), 'accepted_at' => null]);

        return back();
    }

    public function convert(Request $request, Estimate $estimate, InvoiceNumberService $invoiceNumberService): RedirectResponse
    {
        $this->authorizeTeam('estimates.manage', $request);
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
            $calculator = app(InvoiceTotalsCalculator::class);

            $sourceLines = [];
            foreach ((array) $estimate->line_items as $line) {
                $sourceLines[] = [
                    'description' => (string) ($line['description'] ?? ''),
                    'quantity' => (float) ($line['quantity'] ?? 1),
                    'unit_price_cents' => (int) ($line['unit_price_cents'] ?? 0),
                    'vat_rate' => $chargesVat
                        ? (float) ($line['vat_rate'] ?? $defaultVatRate)
                        : 0.0,
                    'item_id' => isset($line['item_id']) ? (int) $line['item_id'] : null,
                    'discount_type' => $line['discount_type'] ?? null,
                    'discount_percent' => $line['discount_percent'] ?? null,
                    'discount_cents' => $line['discount_cents'] ?? null,
                ];
            }

            $totals = $calculator->calculate(
                $sourceLines,
                $estimate->discount_type,
                $estimate->discount_percent,
                $estimate->discount_cents,
            );

            $invoice = Invoice::query()->create([
                'team_id' => $estimate->team_id,
                'client_id' => $estimate->client_id,
                'status' => 'draft',
                'number' => $invoiceNumberService->generate((int) $estimate->team_id),
                'reference' => 'Converted from '.$estimate->number,
                'issue_date' => now()->toDateString(),
                'due_date' => (string) $payload['invoice_due_date'],
                'subtotal_cents' => $totals['subtotal_cents'],
                'vat_amount_cents' => $totals['vat_amount_cents'],
                'total_cents' => $totals['total_cents'],
                'amount_paid_cents' => 0,
                'discount_type' => $estimate->discount_type,
                'discount_percent' => $estimate->discount_type === 'percent' ? $estimate->discount_percent : null,
                'discount_cents' => $estimate->discount_type === 'fixed' ? $estimate->discount_cents : null,
                'discount_total_cents' => $totals['discount_total_cents'],
                'currency' => $estimate->currency ?? 'ZAR',
                'notes' => $payload['invoice_notes'] ?? null,
                'footer' => $payload['invoice_footer'] ?? null,
            ]);

            foreach ($sourceLines as $index => $line) {
                $computed = $totals['lines'][$index];
                $invoice->lineItems()->create([
                    'item_id' => $line['item_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => $line['unit_price_cents'],
                    'vat_rate' => $line['vat_rate'],
                    'discount_type' => $line['discount_type'] ?? null,
                    'discount_percent' => ($line['discount_type'] ?? null) === 'percent' ? ($line['discount_percent'] ?? null) : null,
                    'discount_cents' => ($line['discount_type'] ?? null) === 'fixed' ? ($line['discount_cents'] ?? null) : null,
                    'discount_amount_cents' => $computed['line_discount_cents'],
                    'vat_amount_cents' => $computed['vat_amount_cents'],
                    'total_cents' => $computed['total_cents'],
                    'sort_order' => $index,
                ]);
            }

            $invoice->refresh();
            app(InvoiceBusinessCurrencySnapshot::class)->sync($invoice);

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
        $this->authorizeTeam('estimates.delete', $request);
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
            'discount_type' => ['nullable', Rule::in(['percent', 'fixed'])],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_cents' => ['nullable', 'integer', 'min:0'],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string', 'max:65535'],
            'line_items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'line_items.*.unit_price_cents' => ['required', 'integer', 'min:0'],
            'line_items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'line_items.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('team_id', $teamId)],
            'line_items.*.discount_type' => ['nullable', Rule::in(['percent', 'fixed'])],
            'line_items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'line_items.*.discount_cents' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int, 3: int, 4: int}
     */
    private function calculateTotalsWithDiscounts(
        array $lineItems,
        ?string $documentDiscountType,
        float|int|string|null $documentDiscountPercent,
        int|string|null $documentDiscountCents,
    ): array {
        $calculator = app(InvoiceTotalsCalculator::class);
        $totals = $calculator->calculate(
            $lineItems,
            $documentDiscountType,
            $documentDiscountPercent,
            $documentDiscountCents,
        );

        $enriched = [];
        foreach ($lineItems as $index => $line) {
            $computed = $totals['lines'][$index];
            $enriched[] = [
                ...$line,
                'discount_type' => $line['discount_type'] ?? null,
                'discount_percent' => ($line['discount_type'] ?? null) === 'percent' ? ($line['discount_percent'] ?? null) : null,
                'discount_cents' => ($line['discount_type'] ?? null) === 'fixed' ? ($line['discount_cents'] ?? null) : null,
                'discount_amount_cents' => $computed['line_discount_cents'],
                'vat_amount_cents' => $computed['vat_amount_cents'],
                'total_cents' => $computed['total_cents'],
            ];
        }

        return [
            $enriched,
            $totals['subtotal_cents'],
            $totals['vat_amount_cents'],
            $totals['total_cents'],
            $totals['discount_total_cents'],
        ];
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
     * @return list<array{id: int, name: string, description: string|null, unit: string|null, unit_price_cents: int, default_vat_rate: float|null}>
     */
    private function catalogItemsForForm(int $teamId): array
    {
        return Item::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'unit', 'unit_price_cents', 'default_vat_rate'])
            ->map(fn (Item $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'unit' => $item->unit,
                'unit_price_cents' => (int) $item->unit_price_cents,
                'default_vat_rate' => $item->default_vat_rate !== null ? (float) $item->default_vat_rate : null,
            ])
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
            'discount_type' => $estimate->discount_type,
            'discount_percent' => $estimate->discount_percent !== null ? (float) $estimate->discount_percent : null,
            'discount_cents' => $estimate->discount_cents !== null ? (int) $estimate->discount_cents : null,
            'discount_total_cents' => (int) ($estimate->getRawOriginal('discount_total_cents') ?? 0),
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

        return $team?->mergedBusinessSettings() ?? [];
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
