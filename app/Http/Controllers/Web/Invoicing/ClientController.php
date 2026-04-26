<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $teamId = (int) $request->user()->current_team_id;
        $search = trim((string) $request->string('search')->toString());
        $status = (string) $request->string('status')->toString();
        $view = (string) $request->string('view')->toString() ?: 'grid';

        $query = Client::queryWithoutTeamScope()->where('team_id', $teamId);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $clients = $query
            ->with(['invoices' => fn ($q) => $q->latest('issue_date')])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Client $client): array {
                $invoices = $client->invoices;
                $lastInvoiceDate = optional($invoices->first()?->issue_date)->toDateString();
                $outstanding = $invoices->sum(function (Invoice $invoice): int {
                    if (in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Void], true)) {
                        return 0;
                    }
                    $total = (int) $invoice->getRawOriginal('total_cents');
                    $paid = (int) $invoice->getRawOriginal('amount_paid_cents');

                    return max(0, $total - $paid);
                });

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'contact_name' => $client->contact_name,
                    'email' => $client->email,
                    'status' => $client->is_active ? 'active' : 'inactive',
                    'outstanding_balance_cents' => $outstanding,
                    'last_invoice_date' => $lastInvoiceDate,
                ];
            });

        return Inertia::render('Invoicing/Clients/Index', [
            'clients' => $clients,
            'filters' => [
                'search' => $search ?: null,
                'status' => $status ?: 'all',
                'view' => in_array($view, ['grid', 'table'], true) ? $view : 'grid',
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $returnQuery = $request->query('return');

        return Inertia::render('Invoicing/Clients/Form', [
            'isEditing' => false,
            'client' => null,
            'return_to' => $this->safeInternalReturn(is_string($returnQuery) ? $returnQuery : null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateClient($request);
        $teamId = (int) $request->user()->current_team_id;
        $returnTo = $this->safeInternalReturn(
            is_string($request->input('return')) ? (string) $request->input('return') : null
        );

        $client = Client::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            ...$payload,
        ]);

        if ($returnTo !== null) {
            return redirect($returnTo);
        }

        return to_route('invoicing.clients.show', $client);
    }

    public function show(Request $request, Client $client): Response
    {
        abort_unless($client->team_id === $request->user()->current_team_id, 403);

        $client->loadMissing(['invoices' => fn ($q) => $q->latest('issue_date')]);

        $history = $client->invoices->map(function (Invoice $invoice): array {
            $total = (int) $invoice->getRawOriginal('total_cents');
            $paid = (int) $invoice->getRawOriginal('amount_paid_cents');

            return [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'issue_date' => optional($invoice->issue_date)->toDateString(),
                'due_date' => optional($invoice->due_date)->toDateString(),
                'total_cents' => $total,
                'amount_due_cents' => max(0, $total - $paid),
                'status' => $invoice->status->value,
            ];
        })->values()->all();

        $totalInvoiced = $client->invoices->sum(fn (Invoice $invoice): int => (int) $invoice->getRawOriginal('total_cents'));
        $totalPaid = $client->invoices->sum(fn (Invoice $invoice): int => (int) $invoice->getRawOriginal('amount_paid_cents'));
        $outstanding = max(0, $totalInvoiced - $totalPaid);

        return Inertia::render('Invoicing/Clients/Show', [
            'client' => $this->serializeClient($client),
            'invoice_history' => $history,
            'stats' => [
                'outstanding_balance_cents' => $outstanding,
                'total_invoiced_cents' => $totalInvoiced,
                'total_paid_cents' => $totalPaid,
            ],
        ]);
    }

    public function edit(Request $request, Client $client): Response
    {
        abort_unless($client->team_id === $request->user()->current_team_id, 403);

        return Inertia::render('Invoicing/Clients/Form', [
            'isEditing' => true,
            'client' => $this->serializeClient($client),
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        abort_unless($client->team_id === $request->user()->current_team_id, 403);

        $client->update($this->validateClient($request));

        return to_route('invoicing.clients.show', $client);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateClient(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'vat_number' => ['nullable', 'regex:/^4\d{9}$/'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'array'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.province' => ['nullable', 'string', 'max:255'],
            'address.postal_code' => ['nullable', 'string', 'max:30'],
            'address.country' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', Rule::in(['ZAR'])],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /**
     * Allow only same-origin paths under /invoicing/ (prevents open redirects).
     */
    private function safeInternalReturn(?string $return): ?string
    {
        if ($return === null || $return === '') {
            return null;
        }

        $trimmed = trim($return);
        if ($trimmed === '' || str_contains($trimmed, '..') || str_contains($trimmed, "\0")) {
            return null;
        }

        if (! preg_match('#^/invoicing/#', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    private function serializeClient(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'contact_name' => $client->contact_name,
            'email' => $client->email,
            'phone' => $client->phone,
            'vat_number' => $client->vat_number,
            'registration_number' => $client->registration_number,
            'address' => $client->address ?? [
                'street' => '',
                'city' => '',
                'province' => '',
                'postal_code' => '',
                'country' => 'South Africa',
            ],
            'currency' => $client->currency ?? 'ZAR',
            'payment_terms_days' => (int) $client->payment_terms_days,
            'notes' => $client->notes,
            'is_active' => (bool) $client->is_active,
        ];
    }
}
