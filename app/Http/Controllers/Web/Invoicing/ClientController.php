<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\NoteTemplate;
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
        $this->authorizeTeam('clients.view', $request);

        $teamId = (int) $request->user()->current_team_id;
        $search = trim((string) $request->string('search')->toString());
        $status = (string) $request->string('status')->toString();
        $status = in_array($status, ['active', 'inactive'], true) ? $status : 'active';

        $query = Client::queryWithoutTeamScope()->where('team_id', $teamId);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $query->where('is_active', $status === 'active');

        $clients = $query
            ->with(['invoices' => fn ($q) => $q->latest('issue_date')])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Client $client): array {
                $invoices = $client->invoices;
                $outstanding = $invoices->sum(function (Invoice $invoice): int {
                    if (! $invoice->status->isOpen()) {
                        return 0;
                    }
                    $total = (int) $invoice->getRawOriginal('total_cents');
                    $paid = (int) $invoice->getRawOriginal('amount_paid_cents');

                    return max(0, $total - $paid);
                });

                $issued = $invoices->filter(fn (Invoice $invoice): bool => $invoice->status->isIssued());
                $lastInvoiceDate = optional($issued->sortByDesc(fn (Invoice $invoice) => optional($invoice->issue_date)?->toDateString())->first()?->issue_date)->toDateString();

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
                'status' => $status,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeTeam('clients.manage', $request);

        $returnQuery = $request->query('return');
        $teamId = (int) $request->user()->current_team_id;

        return Inertia::render('Invoicing/Clients/Form', [
            'isEditing' => false,
            'client' => null,
            'note_templates' => $this->noteTemplatesForForm($teamId),
            'return_to' => $this->safeInternalReturn(is_string($returnQuery) ? $returnQuery : null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTeam('clients.manage', $request);

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
        $this->authorizeTeam('clients.view', $request);
        abort_unless($client->team_id === $request->user()->current_team_id, 403);

        $teamId = (int) $client->team_id;
        $today = now()->toDateString();
        $statusesWherePastDueMatters = InvoiceStatus::openStatuses();

        $invoiceHistory = Invoice::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('client_id', $client->id)
            ->issued()
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
            ->issued()
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
            if ($inv->status->isOpen()) {
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
        $this->authorizeTeam('clients.manage', $request);
        abort_unless($client->team_id === $request->user()->current_team_id, 403);

        return Inertia::render('Invoicing/Clients/Form', [
            'isEditing' => true,
            'client' => $this->serializeClient($client),
            'note_templates' => $this->noteTemplatesForForm((int) $client->team_id),
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeTeam('clients.manage', $request);
        abort_unless($client->team_id === $request->user()->current_team_id, 403);

        $payload = $this->validateClient($request);
        $client->update($payload);

        return to_route('invoicing.clients.show', $client);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateClient(Request $request): array
    {
        $teamId = (int) $request->user()->current_team_id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', (new Phone)->country('ZA')->international()],
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
            'default_invoice_notes' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (! empty($validated['phone'])) {
            $validated['phone'] = (new PhoneNumber((string) $validated['phone'], ['ZA']))->formatE164();
        } else {
            $validated['phone'] = null;
        }

        return $validated;
    }

    /**
     * @return list<array{id: int, name: string, body: string, target: string}>
     */
    private function noteTemplatesForForm(int $teamId): array
    {
        return NoteTemplate::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->where('target', 'notes')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'body', 'target'])
            ->map(fn (NoteTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'body' => $template->body,
                'target' => $template->target,
            ])
            ->all();
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
            'default_invoice_notes' => $client->default_invoice_notes,
            'is_active' => (bool) $client->is_active,
        ];
    }
}
