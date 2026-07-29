<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Invoicing\Actions\GenerateRecurringInvoiceAction;
use App\Domain\Invoicing\Enums\RecurringDueDateRule;
use App\Domain\Invoicing\Enums\RecurringFrequency;
use App\Domain\Invoicing\Enums\RecurringInvoiceStatus;
use App\Domain\Invoicing\Enums\RecurringLimitType;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Item;
use App\Domain\Invoicing\Models\NoteTemplate;
use App\Domain\Invoicing\Models\RecurringInvoice;
use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
use App\Support\Iso4217Currencies;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RecurringInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeTeam('invoices.view', $request);
        $teamId = (int) $request->user()->current_team_id;
        $status = (string) $request->string('status')->toString();
        $search = trim((string) $request->string('search')->toString());

        $base = RecurringInvoice::queryWithoutTeamScope()->where('team_id', $teamId);

        $summary = [
            'active' => (clone $base)->where('status', RecurringInvoiceStatus::Active->value)->count(),
            'on_hold' => (clone $base)->where('status', RecurringInvoiceStatus::OnHold->value)->count(),
            'completed' => (clone $base)->where('status', RecurringInvoiceStatus::Completed->value)->count(),
            'due_soon' => (clone $base)
                ->where('status', RecurringInvoiceStatus::Active->value)
                ->whereDate('next_run_date', '<=', now()->addDays(7)->toDateString())
                ->count(),
        ];

        $query = RecurringInvoice::queryWithoutTeamScope()
            ->with('client:id,name')
            ->where('team_id', $teamId);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('reference', 'like', '%'.$search.'%')
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', '%'.$search.'%'));
            });
        }

        $rows = $query->orderBy('next_run_date')->paginate(25)->withQueryString()
            ->through(fn (RecurringInvoice $row): array => $this->serializeList($row));

        return Inertia::render('Invoicing/Recurring/Index', [
            'recurring' => $rows,
            'summary' => $summary,
            'filters' => [
                'status' => $status !== '' ? $status : 'all',
                'search' => $search !== '' ? $search : null,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeTeam('invoices.manage', $request);

        return Inertia::render('Invoicing/Recurring/Form', [
            'isEditing' => false,
            'recurring' => null,
            ...$this->formMeta($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTeam('invoices.manage', $request);
        $teamId = (int) $request->user()->current_team_id;
        $payload = $this->validatePayload($request, $teamId);

        $recurring = RecurringInvoice::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            ...$payload,
            'generated_count' => 0,
            'status' => RecurringInvoiceStatus::Active->value,
        ]);

        return to_route('invoicing.recurring.show', $recurring)
            ->with('success', __('Recurring invoice created.'));
    }

    public function show(Request $request, RecurringInvoice $recurring): Response
    {
        $this->authorizeTeam('invoices.view', $request);
        abort_unless($recurring->team_id === $request->user()->current_team_id, 403);
        $recurring->load(['client:id,name,email', 'invoices' => fn ($q) => $q->latest('issue_date')->limit(20)]);

        return Inertia::render('Invoicing/Recurring/Show', [
            'recurring' => $this->serializeDetail($recurring),
            'can' => [
                'manage' => $request->user()->canOnTeam('invoices.manage', $request->user()->currentTeam),
                'delete' => $request->user()->canOnTeam('invoices.delete', $request->user()->currentTeam),
            ],
        ]);
    }

    public function edit(Request $request, RecurringInvoice $recurring): Response
    {
        $this->authorizeTeam('invoices.manage', $request);
        abort_unless($recurring->team_id === $request->user()->current_team_id, 403);

        return Inertia::render('Invoicing/Recurring/Form', [
            'isEditing' => true,
            'recurring' => $this->serializeDetail($recurring),
            ...$this->formMeta($request),
        ]);
    }

    public function update(Request $request, RecurringInvoice $recurring): RedirectResponse
    {
        $this->authorizeTeam('invoices.manage', $request);
        abort_unless($recurring->team_id === $request->user()->current_team_id, 403);
        $payload = $this->validatePayload($request, (int) $recurring->team_id);
        $recurring->update($payload);

        return to_route('invoicing.recurring.show', $recurring)
            ->with('success', __('Recurring invoice updated.'));
    }

    public function destroy(Request $request, RecurringInvoice $recurring): RedirectResponse
    {
        $this->authorizeTeam('invoices.delete', $request);
        abort_unless($recurring->team_id === $request->user()->current_team_id, 403);
        $recurring->delete();

        return to_route('invoicing.recurring.index')
            ->with('success', __('Recurring invoice deleted.'));
    }

    public function pause(Request $request, RecurringInvoice $recurring): RedirectResponse
    {
        $this->authorizeTeam('invoices.manage', $request);
        abort_unless($recurring->team_id === $request->user()->current_team_id, 403);
        $recurring->update(['status' => RecurringInvoiceStatus::OnHold]);

        return back()->with('success', __('Recurring invoice paused.'));
    }

    public function resume(Request $request, RecurringInvoice $recurring): RedirectResponse
    {
        $this->authorizeTeam('invoices.manage', $request);
        abort_unless($recurring->team_id === $request->user()->current_team_id, 403);
        $recurring->update(['status' => RecurringInvoiceStatus::Active]);

        return back()->with('success', __('Recurring invoice resumed.'));
    }

    public function complete(Request $request, RecurringInvoice $recurring): RedirectResponse
    {
        $this->authorizeTeam('invoices.manage', $request);
        abort_unless($recurring->team_id === $request->user()->current_team_id, 403);
        $recurring->update(['status' => RecurringInvoiceStatus::Completed]);

        return back()->with('success', __('Recurring invoice marked completed.'));
    }

    public function generateNow(Request $request, RecurringInvoice $recurring, GenerateRecurringInvoiceAction $action): RedirectResponse
    {
        $this->authorizeTeam('invoices.manage', $request);
        abort_unless($recurring->team_id === $request->user()->current_team_id, 403);

        if ($recurring->status !== RecurringInvoiceStatus::Active) {
            $recurring->update(['status' => RecurringInvoiceStatus::Active]);
        }

        $invoice = $action->execute($recurring->fresh(), Carbon::today());
        if ($invoice === null) {
            return back()->with('error', __('Could not generate invoice. Check the template is active and the client is active.'));
        }

        return to_route('invoicing.invoices.show', $invoice)
            ->with('success', __('Invoice generated from recurring template.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, int $teamId): array
    {
        $request->merge([
            'generate_on_last_day' => $request->boolean('generate_on_last_day'),
            'auto_send' => $request->boolean('auto_send'),
        ]);

        $validated = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('team_id', $teamId)],
            'frequency' => ['required', Rule::enum(RecurringFrequency::class)],
            'generate_on_weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
            'generate_on_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'generate_on_last_day' => ['required', 'boolean'],
            'generate_on_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'limit_type' => ['required', Rule::enum(RecurringLimitType::class)],
            'limit_count' => ['nullable', 'integer', 'min:1'],
            'limit_end_date' => ['nullable', 'date'],
            'next_run_date' => ['required', 'date'],
            'auto_send' => ['required', 'boolean'],
            'period_offset_months' => ['required', 'integer', 'min:-12', 'max:12'],
            'due_date_rule' => ['required', Rule::enum(RecurringDueDateRule::class)],
            'due_days' => ['nullable', 'integer', 'min:0'],
            'due_on_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Iso4217Currencies::allowedCodes())],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'footer' => ['nullable', 'string'],
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string', 'max:65535'],
            'line_items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'line_items.*.unit_price_cents' => ['required', 'integer', 'min:0'],
            'line_items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'line_items.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('team_id', $teamId)],
            'line_items.*.discount_type' => ['nullable', Rule::in(['percent', 'fixed'])],
            'line_items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'line_items.*.discount_cents' => ['nullable', 'integer', 'min:0'],
            'line_items.*.income_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn ($q) => $q->where('team_id', $teamId)->where('type', AccountType::Income->value)),
            ],
        ]);

        $validated['currency'] = Iso4217Currencies::normalize((string) $validated['currency']);
        $validated['generate_on_last_day'] = (bool) $validated['generate_on_last_day'];
        $validated['auto_send'] = (bool) $validated['auto_send'];
        $validated['line_items'] = $this->normalizeLineItems($validated['line_items']);

        return $validated;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return list<array{
     *     description: string,
     *     quantity: float|int|string,
     *     unit_price_cents: int,
     *     vat_rate: float|null,
     *     item_id: int|null,
     *     discount_type: string|null,
     *     discount_percent: float|null,
     *     discount_cents: int|null,
     *     income_account_id: int|null
     * }>
     */
    private function normalizeLineItems(array $lineItems): array
    {
        return collect($lineItems)
            ->map(function (array $line): array {
                $discountType = $line['discount_type'] ?? null;

                return [
                    'description' => (string) ($line['description'] ?? ''),
                    'quantity' => $line['quantity'] ?? 1,
                    'unit_price_cents' => (int) ($line['unit_price_cents'] ?? 0),
                    'vat_rate' => array_key_exists('vat_rate', $line) && $line['vat_rate'] !== null
                        ? (float) $line['vat_rate']
                        : null,
                    'item_id' => isset($line['item_id']) ? (int) $line['item_id'] : null,
                    'discount_type' => $discountType,
                    'discount_percent' => $discountType === 'percent'
                        ? (isset($line['discount_percent']) ? (float) $line['discount_percent'] : null)
                        : null,
                    'discount_cents' => $discountType === 'fixed'
                        ? (isset($line['discount_cents']) ? (int) $line['discount_cents'] : null)
                        : null,
                    'income_account_id' => isset($line['income_account_id']) ? (int) $line['income_account_id'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formMeta(Request $request): array
    {
        $teamId = (int) $request->user()->current_team_id;
        $team = $request->user()->currentTeam;
        $chargesVat = $team?->chargesVat() ?? false;
        $settings = $team?->mergedBusinessSettings() ?? [];

        return [
            'clients' => Client::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'currency', 'payment_terms_days'])
                ->map(fn (Client $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'currency' => Iso4217Currencies::normalize((string) ($c->currency ?? 'ZAR')),
                    'payment_terms_days' => (int) $c->payment_terms_days,
                ])->all(),
            'items' => Item::queryWithoutTeamScope()
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
                ])->all(),
            'charges_vat' => $chargesVat,
            'default_currency' => Iso4217Currencies::normalize((string) ($settings['invoice_default_currency'] ?? 'ZAR')),
            'tax_rates' => $chargesVat
                ? TaxRate::queryWithoutTeamScope()
                    ->where('team_id', $teamId)
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->get(['id', 'name', 'rate', 'is_default'])
                    ->map(fn (TaxRate $r) => [
                        'id' => $r->id,
                        'name' => $r->name,
                        'rate' => (float) $r->rate,
                        'is_default' => (bool) $r->is_default,
                    ])->all()
                : [],
            'note_templates' => NoteTemplate::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('target', 'notes')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'body', 'target'])
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'body' => $t->body,
                    'target' => $t->target,
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
                ])->all(),
            'default_income_account_id' => Account::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('type', AccountType::Income->value)
                ->where('is_active', true)
                ->where('code', '4000')
                ->value('id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeList(RecurringInvoice $row): array
    {
        return [
            'id' => $row->id,
            'client_name' => $row->client?->name ?? 'Unknown',
            'status' => $row->status->value,
            'frequency' => $row->frequency->value,
            'next_run_date' => optional($row->next_run_date)->toDateString(),
            'generated_count' => (int) $row->generated_count,
            'auto_send' => (bool) $row->auto_send,
            'currency' => Iso4217Currencies::normalize((string) $row->currency),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDetail(RecurringInvoice $row): array
    {
        return [
            ...$this->serializeList($row),
            'client_id' => $row->client_id,
            'generate_on_weekday' => $row->generate_on_weekday,
            'generate_on_day' => $row->generate_on_day,
            'generate_on_last_day' => (bool) $row->generate_on_last_day,
            'generate_on_month' => $row->generate_on_month,
            'limit_type' => $row->limit_type->value,
            'limit_count' => $row->limit_count,
            'limit_end_date' => optional($row->limit_end_date)->toDateString(),
            'period_offset_months' => (int) $row->period_offset_months,
            'due_date_rule' => $row->due_date_rule->value,
            'due_days' => $row->due_days,
            'due_on_day' => $row->due_on_day,
            'reference' => $row->reference,
            'notes' => $row->notes,
            'footer' => $row->footer,
            'line_items' => $row->line_items,
            'last_generated_at' => optional($row->last_generated_at)?->toIso8601String(),
            'invoices' => $row->relationLoaded('invoices')
                ? $row->invoices->map(fn ($invoice) => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'issue_date' => optional($invoice->issue_date)->toDateString(),
                    'total_cents' => (int) $invoice->getRawOriginal('total_cents'),
                    'status' => $invoice->status->value,
                    'currency' => Iso4217Currencies::normalize((string) $invoice->currency),
                ])->values()->all()
                : [],
        ];
    }
}
