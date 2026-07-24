<?php

namespace App\Http\Middleware;

use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Support\BankingPaymentAccounts;
use App\Domain\Invoicing\Enums\PaymentMethodOptions;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Support\Iso4217Currencies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
            'flash' => fn () => [
                // pull() so Octane/Inertia partial reloads cannot keep re-serving the same toast.
                'success' => $request->session()->pull('success'),
                'error' => $request->session()->pull('error'),
                'warning' => $request->session()->pull('warning'),
                'info' => $request->session()->pull('info'),
            ],
            'csrf_token' => fn () => csrf_token(),
            'vat_enabled' => fn () => $request->user()?->currentTeam?->chargesVat() ?? false,
            'appName' => fn () => (string) config('app.name'),
            'currencyOptions' => fn () => Iso4217Currencies::selectOptions(),
            'company_logo_url' => fn () => $request->user()?->currentTeam?->getFirstMedia('logo')?->getUrl() ?: null,
            'company_currency' => fn () => Iso4217Currencies::normalize(
                (string) ($request->user()?->currentTeam?->mergedCompanySettings()['invoice_default_currency'] ?? 'ZAR')
            ),
            'invoice_payment_methods' => fn () => $request->user()?->current_team_id
                ? PaymentMethodOptions::forInertia()
                : [],
            'banking_deposit_accounts' => function () use ($request) {
                $team = $request->user()?->currentTeam;
                if ($team === null) {
                    return [];
                }

                // Read-only: do not create banking accounts from shared props
                // (that raced under concurrent Inertia visits and surfaced unique violations).
                return BankingPaymentAccounts::forInvoiceDeposit((int) $team->id);
            },
            'commandPalette' => fn () => $this->commandPaletteData($request),
            'session_idle_timeout_minutes' => fn () => (int) (
                $request->user()?->currentTeam?->mergedCompanySettings()['session_idle_timeout_minutes'] ?? 0
            ),
            'ai_enabled' => fn () => (bool) $request->user()?->currentTeam?->aiEnabled(),
            'can_manage_backups' => fn () => $request->user() !== null
                && Gate::forUser($request->user())->allows('manageInstanceBackups'),
            'can_access_backups_exports' => function () use ($request) {
                $user = $request->user();
                if ($user === null) {
                    return false;
                }
                $team = $user->currentTeam;
                $isOwner = $team !== null && $user->ownsTeam($team);

                return $isOwner || Gate::forUser($user)->allows('manageInstanceBackups');
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commandPaletteData(Request $request): array
    {
        $user = $request->user();
        $teamId = $user?->current_team_id;
        $team = $user?->currentTeam;
        $vatEnabled = $team?->chargesVat() ?? false;

        $quickActions = [
            ['id' => 'new-invoice', 'label' => 'New Invoice', 'href' => route('invoicing.invoices.create'), 'icon' => 'invoice'],
            ['id' => 'new-expense', 'label' => 'New Expense', 'href' => route('expenses.create'), 'icon' => 'expense'],
            ['id' => 'record-payment', 'label' => 'Record Payment', 'href' => route('dashboard').'#outstanding-invoices', 'icon' => 'payment'],
            ['id' => 'new-client', 'label' => 'New Client', 'href' => route('invoicing.clients.create'), 'icon' => 'client'],
        ];

        $navigation = [
            ['id' => 'dashboard', 'label' => 'Dashboard', 'href' => route('dashboard')],
            ['id' => 'invoices', 'label' => 'Invoices', 'href' => route('invoicing.invoices.index')],
            ['id' => 'estimates', 'label' => 'Estimates', 'href' => route('invoicing.estimates.index')],
            ['id' => 'clients', 'label' => 'Clients', 'href' => route('invoicing.clients.index')],
            ['id' => 'expenses', 'label' => 'Expenses', 'href' => route('expenses.index')],
            ['id' => 'suppliers', 'label' => 'Suppliers', 'href' => route('suppliers.index')],
            ['id' => 'banking-transactions', 'label' => 'Banking Transactions', 'href' => route('banking.transactions.index')],
            ['id' => 'banking-accounts', 'label' => 'Bank Accounts', 'href' => route('banking.accounts.index')],
            ['id' => 'accounting-transactions', 'label' => 'Accounting Transactions', 'href' => route('accounting.transactions.index')],
            ['id' => 'general-ledger', 'label' => 'General Ledger', 'href' => route('accounting.journal.index')],
            ['id' => 'chart-of-accounts', 'label' => 'Chart of Accounts', 'href' => route('accounting.accounts.index')],
            ['id' => 'budgets', 'label' => 'Budgets', 'href' => route('budgeting.index')],
            ['id' => 'contracts', 'label' => 'Contracts', 'href' => route('contracting.contracts.index')],
            ['id' => 'company-settings', 'label' => 'Company Settings', 'href' => route('settings.company')],
            ['id' => 'profile', 'label' => 'Profile Settings', 'href' => route('profile.show')],
        ];

        if ($vatEnabled) {
            $navigation = [
                ...$navigation,
                ['id' => 'vat-returns', 'label' => 'VAT Returns', 'href' => route('tax.vat.index')],
                ['id' => 'vat-rates', 'label' => 'VAT Rates', 'href' => route('tax.vat-rates.index')],
                ['id' => 'tax-periods', 'label' => 'Tax Periods', 'href' => route('tax.provisional.index')],
                ['id' => 'tax-documents', 'label' => 'Tax Documents', 'href' => route('tax.documents.index')],
                ['id' => 'profit-loss', 'label' => 'Profit And Loss', 'href' => route('reports.profit-loss')],
                ['id' => 'balance-sheet', 'label' => 'Balance Sheet', 'href' => route('reports.balance-sheet')],
                ['id' => 'cash-flow', 'label' => 'Cash Flow', 'href' => route('reports.cash-flow')],
                ['id' => 'trial-balance', 'label' => 'Trial Balance', 'href' => route('reports.trial-balance')],
            ];
        }

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
                    'href' => route('invoicing.invoices.show', $invoice),
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
                    'href' => route('invoicing.clients.show', $client),
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
                ->map(function (Transaction $transaction) {
                    $href = $transaction->type === TransactionType::Expense
                        ? route('expenses.edit', $transaction)
                        : route('accounting.transactions.index', array_filter([
                            'search' => $transaction->reference ?: $transaction->description,
                        ]));

                    return [
                        'id' => $transaction->id,
                        'label' => $transaction->description ?: ucfirst($transaction->type->value),
                        'subtitle' => optional($transaction->transaction_date)->format('d M Y'),
                        'href' => $href,
                    ];
                })
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
