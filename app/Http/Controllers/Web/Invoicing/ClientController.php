<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Http\Controllers\Controller;
use App\Support\Iso4217Currencies;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

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

        $teamId = (int) $client->team_id;
        $today = now()->toDateString();
        $statusesWherePastDueMatters = [
            InvoiceStatus::Sent,
            InvoiceStatus::Viewed,
            InvoiceStatus::Partial,
            InvoiceStatus::Overdue,
        ];

        $invoiceHistory = Invoice::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('client_id', $client->id)
            ->orderByDesc('issue_date')
            ->paginate(25)
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
                    'number' => $invoice->number,
                    'issue_date' => optional($invoice->issue_date)->toDateString(),
                    'due_date' => optional($invoice->due_date)->toDateString(),
                    'total_cents' => $total,
                    'amount_due_cents' => $amountDue,
                    'status' => $invoice->status->value,
                    'currency' => Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR')),
                    'is_overdue' => $isOverdue,
                    'days_overdue' => $isOverdue
                        ? abs(Carbon::parse($invoice->due_date)->diffInDays(Carbon::parse($today)))
                        : 0,
                ];
            });

        $statsRows = Invoice::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('client_id', $client->id)
            ->get(['currency', 'status', 'total_cents', 'amount_paid_cents']);

        /** @var array<string, array{outstanding_cents: int, invoiced_cents: int, paid_cents: int}> $byCurrency */
        $byCurrency = [];
        foreach ($statsRows as $inv) {
            $code = Iso4217Currencies::normalize((string) ($inv->currency ?? 'ZAR'));
            if (! isset($byCurrency[$code])) {
                $byCurrency[$code] = [
                    'outstanding_cents' => 0,
                    'invoiced_cents' => 0,
                    'paid_cents' => 0,
                ];
            }
            $total = (int) $inv->getRawOriginal('total_cents');
            $paid = (int) $inv->getRawOriginal('amount_paid_cents');
            $byCurrency[$code]['invoiced_cents'] += $total;
            $byCurrency[$code]['paid_cents'] += $paid;
            if (! in_array($inv->status, [InvoiceStatus::Paid, InvoiceStatus::Void], true)) {
                $byCurrency[$code]['outstanding_cents'] += max(0, $total - $paid);
            }
        }
        ksort($byCurrency);
        $statsByCurrency = [];
        foreach ($byCurrency as $currency => $amounts) {
            $statsByCurrency[] = [
                'currency' => $currency,
                'outstanding_cents' => $amounts['outstanding_cents'],
                'invoiced_cents' => $amounts['invoiced_cents'],
                'paid_cents' => $amounts['paid_cents'],
            ];
        }

        return Inertia::render('Invoicing/Clients/Show', [
            'client' => $this->serializeClient($client),
            'invoice_history' => $invoiceHistory,
            'stats_by_currency' => $statsByCurrency,
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
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', (new Phone)->international()],
            'vat_number' => ['nullable', 'regex:/^4\d{9}$/'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'array'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.province' => ['nullable', 'string', 'max:255'],
            'address.postal_code' => ['nullable', 'string', 'max:30'],
            'address.country' => ['nullable', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! empty($validated['phone'])) {
            $validated['phone'] = (new PhoneNumber((string) $validated['phone']))->formatE164();
        } else {
            $validated['phone'] = null;
        }

        return $validated;
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
            'currency' => Iso4217Currencies::normalize((string) ($client->currency ?? 'ZAR')),
            'payment_terms_days' => (int) $client->payment_terms_days,
            'notes' => $client->notes,
            'is_active' => (bool) $client->is_active,
        ];
    }
}
