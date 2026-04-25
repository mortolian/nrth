<?php

namespace App\Http\Middleware;

use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Accounting\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'commandPalette' => fn () => $this->commandPaletteData($request),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commandPaletteData(Request $request): array
    {
        $user = $request->user();
        $teamId = $user?->current_team_id;

        $quickActions = [
            ['id' => 'new-invoice', 'label' => 'New Invoice', 'href' => '#', 'icon' => 'invoice'],
            ['id' => 'new-expense', 'label' => 'New Expense', 'href' => '#', 'icon' => 'expense'],
            ['id' => 'record-payment', 'label' => 'Record Payment', 'href' => '#', 'icon' => 'payment'],
            ['id' => 'new-client', 'label' => 'New Client', 'href' => '#', 'icon' => 'client'],
        ];

        $navigation = [
            ['id' => 'dashboard', 'label' => 'Dashboard', 'href' => route('dashboard')],
            ['id' => 'profile', 'label' => 'Profile Settings', 'href' => route('profile.show')],
        ];

        if (! $teamId) {
            return ['quickActions' => $quickActions, 'navigation' => $navigation, 'recent' => []];
        }

        $recentInvoices = [];
        if (Schema::hasTable('invoices')) {
            $recentInvoices = Invoice::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->latest('updated_at')
                ->limit(5)
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'label' => 'Invoice '.$invoice->number,
                    'subtitle' => optional($invoice->issue_date)->format('d M Y'),
                    'href' => '#',
                ])
                ->all();
        }

        $recentClients = [];
        if (Schema::hasTable('clients')) {
            $recentClients = Client::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->latest('updated_at')
                ->limit(5)
                ->get()
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'label' => $client->name,
                    'subtitle' => $client->email,
                    'href' => '#',
                ])
                ->all();
        }

        $recentTransactions = [];
        if (Schema::hasTable('transactions')) {
            $recentTransactions = Transaction::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->latest('updated_at')
                ->limit(5)
                ->get()
                ->map(fn (Transaction $transaction) => [
                    'id' => $transaction->id,
                    'label' => $transaction->description ?: ucfirst($transaction->type->value),
                    'subtitle' => optional($transaction->transaction_date)->format('d M Y'),
                    'href' => '#',
                ])
                ->all();
        }

        return [
            'quickActions' => $quickActions,
            'navigation' => $navigation,
            'recent' => [
                'invoices' => $recentInvoices,
                'clients' => $recentClients,
                'transactions' => $recentTransactions,
            ],
        ];
    }
}
