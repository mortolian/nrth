<?php

namespace App\Http\Controllers\Web\Contracting;

use App\Domain\Contracting\Models\Contract;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoiceCompanyCurrencySnapshot;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\RedirectResponse;
use Inertia\Response;

class ContractController extends Controller
{
    public function index(Request $request): Response
    {
        $teamId = (int) $request->user()->current_team_id;
        $status = (string) $request->string('status')->toString();
        $clientId = (int) $request->integer('client_id');
        $from = (string) $request->string('from')->toString();
        $to = (string) $request->string('to')->toString();

        $query = Contract::queryWithoutTeamScope()
            ->with('client:id,name')
            ->where('team_id', $teamId);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($clientId > 0) {
            $query->where('client_id', $clientId);
        }
        if ($from !== '') {
            $query->whereDate('start_date', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('start_date', '<=', $to);
        }

        $contracts = $query
            ->orderByDesc('start_date')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Contract $contract) => [
                'id' => $contract->id,
                'client' => $contract->client?->name ?? 'Unknown',
                'title' => $contract->title,
                'start_date' => optional($contract->start_date)->toDateString(),
                'end_date' => optional($contract->end_date)->toDateString(),
                'value' => (int) $contract->contract_value_cents,
                'status' => $contract->status,
                'billing_type' => $contract->billing_type,
            ]);

        $clients = Client::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name])
            ->all();

        return Inertia::render('Contracting/Contracts/Index', [
            'contracts' => $contracts,
            'clients' => $clients,
            'filters' => [
                'status' => $status ?: 'all',
                'client_id' => $clientId ?: null,
                'from' => $from ?: null,
                'to' => $to ?: null,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Contracting/Contracts/Form', [
            'isEditing' => false,
            'contract' => null,
            ...$this->meta($request),
        ]);
    }

    public function edit(Request $request, Contract $contract): Response
    {
        abort_unless($contract->team_id === $request->user()->current_team_id, 403);

        return Inertia::render('Contracting/Contracts/Form', [
            'isEditing' => true,
            'contract' => $this->serialize($contract),
            ...$this->meta($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateContract($request);
        $teamId = (int) $request->user()->current_team_id;

        $contract = Contract::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            ...$payload,
            'next_invoice_due_date' => $payload['billing_type'] === 'retainer'
                ? Carbon::parse($payload['start_date'])->addMonth()->startOfMonth()->toDateString()
                : null,
        ]);

        if ($request->hasFile('signed_contract')) {
            $contract->addMediaFromRequest('signed_contract')->toMediaCollection('signed-contract');
        }

        return to_route('contracting.contracts.index');
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($contract->team_id === $request->user()->current_team_id, 403);
        $payload = $this->validateContract($request);

        $contract->update([
            ...$payload,
            'next_invoice_due_date' => $payload['billing_type'] === 'retainer'
                ? ($contract->next_invoice_due_date?->toDateString() ?? Carbon::parse($payload['start_date'])->addMonth()->startOfMonth()->toDateString())
                : null,
        ]);

        if ($request->hasFile('signed_contract')) {
            $contract->clearMediaCollection('signed-contract');
            $contract->addMediaFromRequest('signed_contract')->toMediaCollection('signed-contract');
        }

        return to_route('contracting.contracts.index');
    }

    public function generateInvoice(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($contract->team_id === $request->user()->current_team_id, 403);
        abort_unless($contract->billing_type === 'retainer', 400);

        $start = now()->startOfMonth();
        $number = sprintf('RET-%d-%04d', $start->year, $contract->id);
        $amount = (int) $contract->monthly_amount_cents;

        $invoice = Invoice::queryWithoutTeamScope()->create([
            'team_id' => $contract->team_id,
            'client_id' => $contract->client_id,
            'status' => InvoiceStatus::Draft,
            'number' => $number.'-'.now()->format('His'),
            'reference' => 'Retainer: '.$contract->title,
            'issue_date' => $start->toDateString(),
            'due_date' => $start->copy()->addDays(30)->toDateString(),
            'subtotal_cents' => $amount,
            'vat_amount_cents' => 0,
            'total_cents' => $amount,
            'amount_paid_cents' => 0,
            'currency' => 'ZAR',
            'notes' => 'Generated from retainer contract',
            'footer' => null,
        ]);

        $invoice->lineItems()->create([
            'description' => 'Monthly retainer - '.$contract->title,
            'quantity' => 1,
            'unit_price_cents' => $amount,
            'vat_rate' => 0,
            'vat_amount_cents' => 0,
            'total_cents' => $amount,
            'sort_order' => 0,
        ]);

        $invoice->refresh();
        app(InvoiceCompanyCurrencySnapshot::class)->sync($invoice);

        $contract->next_invoice_due_date = now()->addMonth()->startOfMonth();
        $contract->save();

        return to_route('invoicing.invoices.show', $invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateContract(Request $request): array
    {
        $teamId = (int) $request->user()->current_team_id;

        return $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('team_id', $teamId)],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['draft', 'active', 'expired', 'terminated'])],
            'billing_type' => ['required', Rule::in(['fixed', 'time_materials', 'retainer'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'contract_value_cents' => ['nullable', 'integer', 'min:0'],
            'hourly_rate_cents' => ['nullable', 'integer', 'min:0'],
            'monthly_amount_cents' => ['nullable', 'integer', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'scope_of_work' => ['nullable', 'string'],
            'signed_contract' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(Request $request): array
    {
        $teamId = (int) $request->user()->current_team_id;

        return [
            'clients' => Client::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'client_id' => $contract->client_id,
            'title' => $contract->title,
            'status' => $contract->status,
            'billing_type' => $contract->billing_type,
            'start_date' => optional($contract->start_date)->toDateString(),
            'end_date' => optional($contract->end_date)->toDateString(),
            'contract_value_cents' => (int) $contract->contract_value_cents,
            'hourly_rate_cents' => (int) $contract->hourly_rate_cents,
            'monthly_amount_cents' => (int) $contract->monthly_amount_cents,
            'payment_terms' => $contract->payment_terms,
            'scope_of_work' => $contract->scope_of_work,
            'next_invoice_due_date' => optional($contract->next_invoice_due_date)->toDateString(),
            'signed_contract_url' => $contract->getFirstMediaUrl('signed-contract') ?: null,
        ];
    }
}
