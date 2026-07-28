<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Invoicing\Models\Item;
use App\Domain\Tax\Models\TaxRate;
use App\Http\Controllers\Controller;
use App\Support\Iso4217Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ItemController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeTeam('items.view', $request);

        $teamId = (int) $request->user()->current_team_id;
        $search = trim((string) $request->string('search')->toString());
        $status = (string) $request->string('status')->toString();

        $query = Item::queryWithoutTeamScope()->where('team_id', $teamId);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $items = $query
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Item $item): array => $this->serializeItem($item));

        $base = Item::queryWithoutTeamScope()->where('team_id', $teamId);
        $summary = [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('is_active', true)->count(),
            'inactive' => (clone $base)->where('is_active', false)->count(),
        ];

        return Inertia::render('Invoicing/Items/Index', [
            'items' => $items,
            'summary' => $summary,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'status' => $status !== '' ? $status : 'all',
            ],
            'default_currency' => Iso4217Currencies::normalize(
                (string) ($request->user()->currentTeam?->mergedBusinessSettings()['invoice_default_currency'] ?? 'ZAR')
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeTeam('items.manage', $request);

        return Inertia::render('Invoicing/Items/Form', [
            'isEditing' => false,
            'item' => null,
            ...$this->formShared($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTeam('items.manage', $request);

        $teamId = (int) $request->user()->current_team_id;
        $payload = $this->validateItem($request, $teamId);

        $item = Item::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            ...$payload,
        ]);

        return to_route('invoicing.items.show', $item)
            ->with('success', __('Item created.'));
    }

    public function show(Request $request, Item $item): Response
    {
        $this->authorizeTeam('items.view', $request);
        abort_unless($item->team_id === $request->user()->current_team_id, 403);

        return Inertia::render('Invoicing/Items/Show', [
            'item' => $this->serializeItem($item),
            'default_currency' => Iso4217Currencies::normalize(
                (string) ($request->user()->currentTeam?->mergedBusinessSettings()['invoice_default_currency'] ?? 'ZAR')
            ),
            'can' => [
                'manage' => $request->user()->canOnTeam('items.manage', $request->user()->currentTeam),
                'delete' => $request->user()->canOnTeam('items.delete', $request->user()->currentTeam),
            ],
        ]);
    }

    public function edit(Request $request, Item $item): Response
    {
        $this->authorizeTeam('items.manage', $request);
        abort_unless($item->team_id === $request->user()->current_team_id, 403);

        return Inertia::render('Invoicing/Items/Form', [
            'isEditing' => true,
            'item' => $this->serializeItem($item),
            ...$this->formShared($request),
        ]);
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $this->authorizeTeam('items.manage', $request);
        abort_unless($item->team_id === $request->user()->current_team_id, 403);

        $payload = $this->validateItem($request, (int) $item->team_id, $item->id);
        $item->update($payload);

        return to_route('invoicing.items.show', $item)
            ->with('success', __('Item updated.'));
    }

    public function destroy(Request $request, Item $item): RedirectResponse
    {
        $this->authorizeTeam('items.delete', $request);
        abort_unless($item->team_id === $request->user()->current_team_id, 403);

        $item->delete();

        return to_route('invoicing.items.index')
            ->with('success', __('Item deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formShared(Request $request): array
    {
        $team = $request->user()->currentTeam;
        $teamId = (int) $request->user()->current_team_id;
        $chargesVat = (bool) ($team?->chargesVat() ?? false);
        $settings = $team?->mergedBusinessSettings() ?? [];

        return [
            'default_vat_rate' => $chargesVat ? (float) $team->defaultVatRateForInvoicing() : 0.0,
            'charges_vat' => $chargesVat,
            'default_currency' => Iso4217Currencies::normalize(
                (string) ($settings['invoice_default_currency'] ?? 'ZAR')
            ),
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateItem(Request $request, int $teamId, ?int $ignoreId = null): array
    {
        $uniqueName = Rule::unique('items', 'name')
            ->where(fn ($query) => $query->where('team_id', $teamId));

        if ($ignoreId !== null) {
            $uniqueName = $uniqueName->ignore($ignoreId);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $uniqueName],
            'description' => ['nullable', 'string', 'max:65535'],
            'unit' => ['nullable', 'string', 'max:32'],
            'unit_price_cents' => ['required', 'integer', 'min:0'],
            'default_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'is_active' => ['required', 'boolean'],
        ]);

        $validated['description'] = isset($validated['description']) && trim((string) $validated['description']) !== ''
            ? trim((string) $validated['description'])
            : null;
        $validated['unit'] = isset($validated['unit']) && trim((string) $validated['unit']) !== ''
            ? trim((string) $validated['unit'])
            : null;
        $validated['default_vat_rate'] = array_key_exists('default_vat_rate', $validated) && $validated['default_vat_rate'] !== null
            ? (float) $validated['default_vat_rate']
            : null;

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(Item $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'unit' => $item->unit,
            'unit_price_cents' => (int) $item->unit_price_cents,
            'default_vat_rate' => $item->default_vat_rate !== null ? (float) $item->default_vat_rate : null,
            'is_active' => (bool) $item->is_active,
        ];
    }
}
