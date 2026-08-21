<?php

namespace App\Http\Middleware;

use App\Domain\Accounting\Enums\TransactionType;
use App\Domain\Accounting\Models\Transaction;
use App\Domain\Banking\Support\BankingPaymentAccounts;
use App\Domain\Instance\Services\InstanceTimezoneSettings;
use App\Domain\Invoicing\Enums\PaymentMethodOptions;
use App\Domain\Invoicing\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Support\Iso4217Currencies;
use App\Support\Modules\ModuleCatalog;
use App\Support\TeamAccess\TeamAccess;
use App\Support\Upgrade\SchemaUpgradeStatus;
use App\Support\Version\GithubReleaseChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;
use Throwable;

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
            'app_version' => function () {
                try {
                    return app(GithubReleaseChecker::class)->forInertia();
                } catch (Throwable) {
                    $current = '0.0.0';
                    try {
                        $path = base_path('version.txt');
                        if (is_file($path)) {
                            $current = SchemaUpgradeStatus::normalizeVersion(
                                (string) file_get_contents($path)
                            );
                        }
                    } catch (Throwable) {
                    }

                    return [
                        'current' => $current,
                        'latest' => null,
                        'update_available' => false,
                        'url' => null,
                        'docs_url' => 'https://github.com/mortolian/nrth/blob/master/docs/UPGRADE.md',
                    ];
                }
            },
            'app_timezone' => function () use ($request) {
                $team = $request->user()?->currentTeam;
                if ($team !== null) {
                    return $team->timezone();
                }

                return app(InstanceTimezoneSettings::class)->resolved();
            },
            'instance_timezone' => fn () => app(InstanceTimezoneSettings::class)->resolved(),
            'currencyOptions' => fn () => Iso4217Currencies::selectOptions(),
            'business_logo_url' => fn () => $request->user()?->currentTeam?->getFirstMedia('logo')?->getUrl() ?: null,
            'business_currency' => fn () => Iso4217Currencies::normalize(
                (string) ($request->user()?->currentTeam?->mergedBusinessSettings()['invoice_default_currency'] ?? 'ZAR')
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
                $request->user()?->currentTeam?->mergedBusinessSettings()['session_idle_timeout_minutes'] ?? 0
            ),
            'ai_enabled' => fn () => (bool) $request->user()?->currentTeam?->aiEnabled(),
            'enabled_modules' => fn () => $request->user()?->currentTeam?->enabledModules() ?? [],
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
            'team_permissions' => function () use ($request) {
                $user = $request->user();
                if ($user === null) {
                    return [];
                }

                return TeamAccess::permissionsFor($user, $user->currentTeam);
            },
            'can_leave_current_team' => function () use ($request) {
                $user = $request->user();
                $team = $user?->currentTeam;

                return $user !== null
                    && $team !== null
                    && $user->belongsToTeam($team)
                    && ! $user->ownsTeam($team);
            },
            'current_team_role' => function () use ($request) {
                $user = $request->user();
                $team = $user?->currentTeam;

                if ($user === null || $team === null || ! $user->belongsToTeam($team)) {
                    return null;
                }

                return [
                    'key' => TeamAccess::membershipRoleKey($user, $team),
                    'label' => TeamAccess::membershipRoleLabel($user, $team),
                ];
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
        $permissions = $user !== null ? TeamAccess::permissionsFor($user, $team) : [];
        $can = fn (string $permission): bool => in_array($permission, $permissions, true);
        $moduleOn = fn (string $name): bool => $team?->moduleEnabled($name) ?? false;

        $quickActions = array_values(array_filter([
            $can('invoices.manage')
                ? ['id' => 'new-invoice', 'label' => 'New Invoice', 'href' => route('invoicing.invoices.create'), 'icon' => 'invoice']
                : null,
            $can('expenses.manage')
                ? ['id' => 'new-expense', 'label' => 'New Expense', 'href' => route('expenses.create'), 'icon' => 'expense']
                : null,
            $can('invoices.manage')
                ? ['id' => 'record-payment', 'label' => 'Record Payment', 'href' => route('dashboard').'#outstanding-invoices', 'icon' => 'payment']
                : null,
            $can('vehicles.manage') && $moduleOn(ModuleCatalog::TRAVEL)
                ? ['id' => 'log-trip', 'label' => 'Log Trip', 'href' => route('vehicles.trips.create'), 'icon' => 'expense']
                : null,
            $can('clients.manage')
                ? ['id' => 'new-client', 'label' => 'New Client', 'href' => route('invoicing.clients.create'), 'icon' => 'client']
                : null,
            $can('items.manage')
                ? ['id' => 'new-item', 'label' => 'New Item', 'href' => route('invoicing.items.create'), 'icon' => 'invoice']
                : null,
        ]));

        $navigation = array_values(array_filter([
            ['id' => 'dashboard', 'label' => 'Dashboard', 'href' => route('dashboard')],
            $can('invoices.view') ? ['id' => 'invoices', 'label' => 'Invoices', 'href' => route('invoicing.invoices.index')] : null,
            $can('estimates.view') ? ['id' => 'estimates', 'label' => 'Estimates', 'href' => route('invoicing.estimates.index')] : null,
            $can('clients.view') ? ['id' => 'clients', 'label' => 'Clients', 'href' => route('invoicing.clients.index')] : null,
            $can('items.view') ? ['id' => 'items', 'label' => 'Items', 'href' => route('invoicing.items.index')] : null,
            $can('expenses.view') ? ['id' => 'expenses', 'label' => 'Expenses', 'href' => route('expenses.index')] : null,
            $can('suppliers.view') ? ['id' => 'suppliers', 'label' => 'Suppliers', 'href' => route('suppliers.index')] : null,
            $can('banking.view') ? ['id' => 'banking-transactions', 'label' => 'Banking Transactions', 'href' => route('banking.transactions.index')] : null,
            $can('banking.view') ? ['id' => 'banking-reconciliation', 'label' => 'Bank Reconciliation', 'href' => route('banking.reconciliation.index')] : null,
            $can('banking.view') ? ['id' => 'banking-accounts', 'label' => 'Bank Accounts', 'href' => route('banking.accounts.index')] : null,
            ($moduleOn(ModuleCatalog::TRAVEL) && $can('vehicles.view'))
                ? ['id' => 'vehicles-trips', 'label' => 'Trip Log', 'href' => route('vehicles.trips.index')]
                : null,
            ($moduleOn(ModuleCatalog::TRAVEL) && $can('vehicles.view'))
                ? ['id' => 'vehicles', 'label' => 'Vehicles', 'href' => route('vehicles.index')]
                : null,
            $can('accounting.view') ? ['id' => 'accounting-transactions', 'label' => 'Accounting Transactions', 'href' => route('accounting.transactions.index')] : null,
            $can('accounting.view') ? ['id' => 'general-ledger', 'label' => 'General Ledger (period)', 'href' => route('accounting.journal.index')] : null,
            $can('accounting.view') ? ['id' => 'chart-of-accounts', 'label' => 'Chart of Accounts (setup)', 'href' => route('accounting.accounts.index')] : null,
            ($moduleOn(ModuleCatalog::PLANNING) && $can('budgets.view'))
                ? ['id' => 'budgets', 'label' => 'Budgets', 'href' => route('budgeting.index')]
                : null,
            ($moduleOn(ModuleCatalog::WEALTH) && $can('wealth.view'))
                ? ['id' => 'wealth', 'label' => 'Wealth', 'href' => route('wealth.index')]
                : null,
            $can('settings.business') ? ['id' => 'business-settings', 'label' => 'Business Settings', 'href' => route('settings.business')] : null,
            $can('settings.business') ? ['id' => 'note-templates', 'label' => 'Note Templates (Business)', 'href' => route('settings.note-templates.index')] : null,
            $can('settings.business') ? ['id' => 'features-settings', 'label' => 'Features', 'href' => route('settings.features')] : null,
            $can('settings.team') ? ['id' => 'team-settings', 'label' => 'Team Members', 'href' => route('settings.team')] : null,
            ['id' => 'profile', 'label' => 'Profile Settings', 'href' => route('settings.index')],
        ]));

        if ($vatEnabled) {
            $navigation = [
                ...$navigation,
                ...array_values(array_filter([
                    $can('tax.view') ? ['id' => 'vat-returns', 'label' => 'VAT Returns', 'href' => route('tax.vat.index')] : null,
                    $can('tax.manage') ? ['id' => 'vat-rates', 'label' => 'VAT Rates', 'href' => route('tax.vat-rates.index')] : null,
                    $can('tax.manage') ? ['id' => 'tax-documents', 'label' => 'Tax Documents', 'href' => route('tax.documents.index')] : null,
                    $can('reports.view') ? ['id' => 'profit-loss', 'label' => 'Profit And Loss', 'href' => route('reports.profit-loss')] : null,
                    $can('reports.view') ? ['id' => 'balance-sheet', 'label' => 'Balance Sheet', 'href' => route('reports.balance-sheet')] : null,
                    $can('reports.view') ? ['id' => 'cash-flow', 'label' => 'Cash Flow', 'href' => route('reports.cash-flow')] : null,
                    $can('reports.view') ? ['id' => 'trial-balance', 'label' => 'Trial Balance', 'href' => route('reports.trial-balance')] : null,
                ])),
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
