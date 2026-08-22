<?php

use App\Http\Controllers\Web\Accounting\AccountController;
use App\Http\Controllers\Web\Accounting\AccountStatementController;
use App\Http\Controllers\Web\Accounting\ChartOfAccountsController;
use App\Http\Controllers\Web\Accounting\GeneralLedgerController;
use App\Http\Controllers\Web\Accounting\TransactionController;
use App\Http\Controllers\Web\BackupsExportsController;
use App\Http\Controllers\Web\Banking\BankingAccountController;
use App\Http\Controllers\Web\Banking\BankingReconciliationController;
use App\Http\Controllers\Web\Banking\BankingStatementImportController;
use App\Http\Controllers\Web\Banking\BankingTransactionController;
use App\Http\Controllers\Web\BudgetingController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ExpensesController;
use App\Http\Controllers\Web\InvoicePdfController;
use App\Http\Controllers\Web\Invoicing\ClientController;
use App\Http\Controllers\Web\Invoicing\EstimateController;
use App\Http\Controllers\Web\Invoicing\EstimatePdfController;
use App\Http\Controllers\Web\Invoicing\ExchangeRateController;
use App\Http\Controllers\Web\Invoicing\InvoiceController;
use App\Http\Controllers\Web\Invoicing\InvoiceOnlinePaymentController;
use App\Http\Controllers\Web\Invoicing\ItemController;
use App\Http\Controllers\Web\Invoicing\PaymentReceiptController;
use App\Http\Controllers\Web\Invoicing\RecurringInvoiceController;
use App\Http\Controllers\Web\JoinTeamInvitationController;
use App\Http\Controllers\Web\OnboardingController;
use App\Http\Controllers\Web\PublicInvoicePayController;
use App\Http\Controllers\Web\ReportsController;
use App\Http\Controllers\Web\Settings\BusinessSettingsController;
use App\Http\Controllers\Web\Settings\FeaturesSettingsController;
use App\Http\Controllers\Web\Settings\InstanceSettingsController;
use App\Http\Controllers\Web\Settings\InstanceTeamsController;
use App\Http\Controllers\Web\Settings\NoteTemplateController;
use App\Http\Controllers\Web\Settings\TeamInvitationController;
use App\Http\Controllers\Web\Settings\TeamRoleController;
use App\Http\Controllers\Web\Settings\TeamSettingsController;
use App\Http\Controllers\Web\Settings\UserPreferencesController;
use App\Http\Controllers\Web\SupplierController;
use App\Http\Controllers\Web\Tax\TakeoutController;
use App\Http\Controllers\Web\Tax\TaxDocumentsController;
use App\Http\Controllers\Web\Tax\VATController;
use App\Http\Controllers\Web\Tax\VatRateController;
use App\Http\Controllers\Web\Vehicles\TripController;
use App\Http\Controllers\Web\Vehicles\TripImportController;
use App\Http\Controllers\Web\Vehicles\VehicleController;
use App\Http\Controllers\Web\Webhooks\PayFastPaymentWebhookController;
use App\Http\Controllers\Web\Webhooks\StripePaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::post('/webhooks/payments/stripe/{team}', StripePaymentWebhookController::class)->name('webhooks.stripe');
Route::post('/webhooks/payments/payfast/{team}', PayFastPaymentWebhookController::class)->name('webhooks.payfast');

Route::get('/pay/{token}', [PublicInvoicePayController::class, 'show'])->where('token', '[a-f0-9]{32}')->name('public.invoice.pay');
Route::post('/pay/{token}/checkout', [PublicInvoicePayController::class, 'checkout'])->where('token', '[a-f0-9]{32}')->name('public.invoice.checkout');
Route::get('/pay/{token}/pdf', [PublicInvoicePayController::class, 'pdf'])->where('token', '[a-f0-9]{32}')->name('public.invoice.pdf');

Route::get('/invitations/{invitation}', JoinTeamInvitationController::class)
    ->middleware(['signed'])
    ->name('team-invitations.join');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/onboarding/setup', [OnboardingController::class, 'show'])->name('onboarding.setup');
    Route::post('/onboarding/progress', [OnboardingController::class, 'saveProgress'])->name('onboarding.progress');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
    Route::post('/onboarding/skip', [OnboardingController::class, 'skip'])->name('onboarding.skip');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/settings', fn () => redirect()->route('profile.show'))->name('settings.index');
    Route::get('/settings/business', [BusinessSettingsController::class, 'edit'])->name('settings.business');
    Route::post('/settings/business', [BusinessSettingsController::class, 'update'])->name('settings.business.update');
    Route::get('/settings/features', [FeaturesSettingsController::class, 'edit'])->name('settings.features');
    Route::put('/settings/features', [FeaturesSettingsController::class, 'update'])->name('settings.features.update');
    Route::get('/settings/note-templates', [NoteTemplateController::class, 'index'])->name('settings.note-templates.index');
    Route::post('/settings/note-templates', [NoteTemplateController::class, 'store'])->name('settings.note-templates.store');
    Route::put('/settings/note-templates/{noteTemplate}', [NoteTemplateController::class, 'update'])->name('settings.note-templates.update');
    Route::delete('/settings/note-templates/{noteTemplate}', [NoteTemplateController::class, 'destroy'])->name('settings.note-templates.destroy');
    Route::get('/settings/company', fn () => redirect()->route('settings.business'));
    Route::post('/settings/company', [BusinessSettingsController::class, 'update']);
    Route::get('/settings/team', [TeamSettingsController::class, 'edit'])->name('settings.team');
    Route::put('/settings/team/{team}/session-idle-timeout', [TeamSettingsController::class, 'updateSessionIdleTimeout'])
        ->name('settings.team.session-idle-timeout');
    Route::post('/settings/team/{team}/roles', [TeamRoleController::class, 'store'])->name('settings.team.roles.store');
    Route::put('/settings/team/{team}/roles/{teamRole}', [TeamRoleController::class, 'update'])->name('settings.team.roles.update');
    Route::delete('/settings/team/{team}/roles/{teamRole}', [TeamRoleController::class, 'destroy'])->name('settings.team.roles.destroy');
    Route::post('/team-invitations/{invitation}/resend', [TeamInvitationController::class, 'resend'])
        ->name('team-invitations.resend');
    Route::get('/settings/instance', [InstanceSettingsController::class, 'edit'])->name('settings.instance');
    Route::get('/settings/instance/teams', [InstanceTeamsController::class, 'index'])->name('settings.instance.teams');
    Route::get('/settings/instance/teams/{team}', [InstanceTeamsController::class, 'show'])->name('settings.instance.teams.show');
    Route::get('/settings/instance/timezone', [InstanceSettingsController::class, 'timezone'])->name('settings.instance.timezone');
    Route::put('/settings/instance/timezone', [InstanceSettingsController::class, 'updateTimezone'])
        ->name('settings.instance.timezone.update');
    Route::get('/settings/instance/mail', [InstanceSettingsController::class, 'mail'])->name('settings.instance.mail');
    Route::get('/settings/instance/operators', [InstanceSettingsController::class, 'operators'])->name('settings.instance.operators');
    Route::put('/settings/instance/backup-retention', [InstanceSettingsController::class, 'updateBackupRetention'])
        ->name('settings.instance.backup-retention.update');
    Route::put('/settings/instance/backup-destinations', [InstanceSettingsController::class, 'updateBackupDestinations'])
        ->name('settings.instance.backup-destinations.update');
    Route::post('/settings/instance/backup-destinations/test-s3', [InstanceSettingsController::class, 'testBackupS3'])
        ->name('settings.instance.backup-destinations.test-s3');
    Route::post('/settings/instance/backup-destinations/test-path', [InstanceSettingsController::class, 'testBackupPath'])
        ->name('settings.instance.backup-destinations.test-path');
    Route::put('/settings/instance/mail', [InstanceSettingsController::class, 'updateMail'])
        ->name('settings.instance.mail.update');
    Route::post('/settings/instance/mail/test', [InstanceSettingsController::class, 'testMail'])
        ->name('settings.instance.mail.test');
    Route::post('/settings/instance/operators', [InstanceSettingsController::class, 'addOperator'])->name('settings.instance.operators.store');
    Route::delete('/settings/instance/operators/{user}', [InstanceSettingsController::class, 'removeOperator'])->name('settings.instance.operators.destroy');
    Route::put('/user/preferences', [UserPreferencesController::class, 'update'])->name('user-preferences.update');
    Route::get('/backups-exports', [BackupsExportsController::class, 'index'])->name('backups-exports.index');
    Route::get('/backups-exports/destinations', [BackupsExportsController::class, 'destinations'])
        ->name('backups-exports.destinations');
    Route::redirect('/backups-exports/mail', '/settings/instance/mail')->name('backups-exports.mail');
    Route::get('/backups-exports/retention', [BackupsExportsController::class, 'retention'])
        ->name('backups-exports.retention');
    Route::get('/backups-exports/restore', [BackupsExportsController::class, 'restore'])
        ->name('backups-exports.restore');
    Route::redirect('/backups-exports/operators', '/settings/instance/operators')->name('backups-exports.operators');
    Route::post('/backups-exports/backups', [BackupsExportsController::class, 'storeBackup'])->name('backups-exports.backups.store');
    Route::get('/backups-exports/backups/{instanceBackupRun}/download', [BackupsExportsController::class, 'downloadBackup'])
        ->name('backups-exports.backups.download');
    Route::delete('/backups-exports/backups/{instanceBackupRun}', [BackupsExportsController::class, 'destroyBackup'])
        ->name('backups-exports.backups.destroy');
    Route::post('/backups-exports/backups/{instanceBackupRun}/retry', [BackupsExportsController::class, 'retryBackup'])
        ->name('backups-exports.backups.retry');
    Route::prefix('banking')->name('banking.')->group(function () {
        Route::get('/reconciliation', [BankingReconciliationController::class, 'index'])->name('reconciliation.index');
        Route::post('/reconciliation/{bankingTransaction}/allocations', [BankingReconciliationController::class, 'storeAllocation'])->name('reconciliation.allocations.store');
        Route::delete('/reconciliation/{bankingTransaction}/allocations/{allocation}', [BankingReconciliationController::class, 'destroyAllocation'])->name('reconciliation.allocations.destroy');
        Route::post('/reconciliation/{bankingTransaction}/exclude', [BankingReconciliationController::class, 'exclude'])->name('reconciliation.exclude');
        Route::post('/reconciliation/{bankingTransaction}/reset', [BankingReconciliationController::class, 'reset'])->name('reconciliation.reset');
        Route::get('/transactions', [BankingTransactionController::class, 'index'])->name('transactions.index');
        Route::get('/accounts', [BankingAccountController::class, 'index'])->name('accounts.index');
        Route::post('/accounts', [BankingAccountController::class, 'store'])->name('accounts.store');
        Route::put('/accounts/{bankingAccount}', [BankingAccountController::class, 'update'])->name('accounts.update');
        Route::get('/imports', [BankingStatementImportController::class, 'index'])->name('imports.index');
        Route::post('/imports/{import}/undo', [BankingStatementImportController::class, 'undo'])->name('imports.undo');
        Route::post('/imports/{import}/reimport', [BankingStatementImportController::class, 'reimport'])->name('imports.reimport');
        Route::get('/import', [BankingStatementImportController::class, 'create'])->name('import.create');
        Route::post('/import', [BankingStatementImportController::class, 'store'])->name('import.store');
        Route::get('/import/{import}/map', [BankingStatementImportController::class, 'map'])->name('import.map');
        Route::post('/import/{import}/map', [BankingStatementImportController::class, 'parseMapping'])->name('import.map.store');
        Route::get('/import/{import}/preview', [BankingStatementImportController::class, 'preview'])->name('import.preview');
        Route::post('/import/{import}/confirm', [BankingStatementImportController::class, 'confirm'])->name('import.confirm');
    });
    Route::get('/expenses', [ExpensesController::class, 'index'])->name('expenses.index');
    Route::get('/expenses/export', [ExpensesController::class, 'exportCsv'])->name('expenses.export');
    Route::get('/expenses/create', [ExpensesController::class, 'create'])->name('expenses.create');
    Route::post('/expenses/parse-receipt', [ExpensesController::class, 'parseReceipt'])->name('expenses.parse-receipt');
    Route::post('/expenses', [ExpensesController::class, 'store'])->name('expenses.store');
    Route::get('/expenses/{transaction}/edit', [ExpensesController::class, 'edit'])->name('expenses.edit');
    Route::put('/expenses/{transaction}', [ExpensesController::class, 'update'])->name('expenses.update');
    Route::delete('/expenses/{transaction}', [ExpensesController::class, 'destroy'])->name('expenses.destroy');
    Route::post('/expenses/{transaction}/receipt', [ExpensesController::class, 'storeReceipt'])->name('expenses.receipt.store');
    Route::get('/expenses/{transaction}/attachments/{media}', [ExpensesController::class, 'showAttachment'])->name('expenses.attachments.show');
    Route::delete('/expenses/{transaction}/attachments/{media}', [ExpensesController::class, 'destroyAttachment'])->name('expenses.attachments.destroy');
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
    Route::post('/suppliers/parse-document', [SupplierController::class, 'parseDocument'])->name('suppliers.parse-document');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
    Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
    Route::get('/accounting/transactions', [TransactionController::class, 'index'])->name('accounting.transactions.index');
    Route::get('/accounting/transactions/export', [TransactionController::class, 'exportCsv'])->name('accounting.transactions.export');
    Route::delete('/accounting/transactions/{transaction}', [TransactionController::class, 'destroy'])->name('accounting.transactions.destroy');
    Route::get('/accounting/journal', GeneralLedgerController::class)->name('accounting.journal.index');
    Route::get('/accounting/accounts', ChartOfAccountsController::class)->name('accounting.accounts.index');
    Route::post('/accounting/accounts/seed-default', [AccountController::class, 'seedDefault'])->name('accounting.accounts.seed-default');
    Route::get('/accounting/accounts/create', [AccountController::class, 'create'])->name('accounting.accounts.create');
    Route::post('/accounting/accounts', [AccountController::class, 'store'])->name('accounting.accounts.store');
    Route::get('/accounting/accounts/{account}/edit', [AccountController::class, 'edit'])->name('accounting.accounts.edit');
    Route::put('/accounting/accounts/{account}', [AccountController::class, 'update'])->name('accounting.accounts.update');
    Route::post('/accounting/accounts/{account}/deactivate', [AccountController::class, 'deactivate'])->name('accounting.accounts.deactivate');
    Route::delete('/accounting/accounts/{account}', [AccountController::class, 'destroy'])->name('accounting.accounts.destroy');
    Route::get('/accounting/accounts/{account}/statement', [AccountStatementController::class, 'show'])->name('accounting.accounts.statement');
    Route::get('/accounting/accounts/{account}/statement/export', [AccountStatementController::class, 'exportCsv'])->name('accounting.accounts.statement.export');
    Route::middleware('team.module:planning')->group(function () {
        Route::get('/budgeting', [BudgetingController::class, 'index'])->name('budgeting.index');
        Route::get('/budgeting/create', [BudgetingController::class, 'create'])->name('budgeting.create');
        Route::post('/budgeting', [BudgetingController::class, 'store'])->name('budgeting.store');
        Route::get('/budgeting/{budget}', [BudgetingController::class, 'show'])->name('budgeting.show');
        Route::get('/budgeting/{budget}/edit', [BudgetingController::class, 'edit'])->name('budgeting.edit');
        Route::put('/budgeting/{budget}', [BudgetingController::class, 'update'])->name('budgeting.update');
        Route::delete('/budgeting/{budget}', [BudgetingController::class, 'destroy'])->name('budgeting.destroy');
        Route::post('/budgeting/trash/{id}/restore', [BudgetingController::class, 'restore'])->whereNumber('id')->name('budgeting.restore');
        Route::delete('/budgeting/trash/{id}', [BudgetingController::class, 'forceDestroy'])->whereNumber('id')->name('budgeting.force-destroy');
        Route::post('/budgeting/{budget}/import-structure', [BudgetingController::class, 'importStructure'])->name('budgeting.import-structure');
        Route::post('/budgeting/{budget}/categories', [BudgetingController::class, 'storeCategory'])->name('budgeting.categories.store');
        Route::put('/budgeting/{budget}/categories/{category}', [BudgetingController::class, 'updateCategory'])->name('budgeting.categories.update');
        Route::delete('/budgeting/{budget}/categories/{category}', [BudgetingController::class, 'destroyCategory'])->name('budgeting.categories.destroy');
        Route::post('/budgeting/{budget}/categories/{category}/items', [BudgetingController::class, 'storeItem'])->name('budgeting.items.store');
        Route::put('/budgeting/{budget}/categories/{category}/items/{item}', [BudgetingController::class, 'updateItem'])->name('budgeting.items.update');
        Route::delete('/budgeting/{budget}/categories/{category}/items/{item}', [BudgetingController::class, 'destroyItem'])->name('budgeting.items.destroy');
    });
    Route::get('/tax/vat', [VATController::class, 'index'])->name('tax.vat.index');
    Route::get('/tax/vat-rates', [VatRateController::class, 'index'])->name('tax.vat-rates.index');
    Route::post('/tax/vat-rates', [VatRateController::class, 'store'])->name('tax.vat-rates.store');
    Route::put('/tax/vat-rates/{taxRate}', [VatRateController::class, 'update'])->name('tax.vat-rates.update');
    Route::delete('/tax/vat-rates/{taxRate}', [VatRateController::class, 'destroy'])->name('tax.vat-rates.destroy');
    Route::post('/tax/vat/periods/{period}/submit', [VATController::class, 'submit'])->name('tax.vat.submit');
    Route::get('/tax/documents', TaxDocumentsController::class)->name('tax.documents.index');
    Route::post('/tax/takeouts', [TakeoutController::class, 'store'])->name('tax.takeouts.store');
    Route::get('/tax/takeouts/{takeoutRun}/download', [TakeoutController::class, 'download'])->name('tax.takeouts.download');
    Route::delete('/tax/takeouts/{takeoutRun}', [TakeoutController::class, 'destroy'])->name('tax.takeouts.destroy');
    Route::post('/tax/takeouts/{takeoutRun}/retry', [TakeoutController::class, 'retry'])->name('tax.takeouts.retry');
    Route::get('/reports/profit-loss', [ReportsController::class, 'profitLoss'])->name('reports.profit-loss');
    Route::get('/reports/balance-sheet', [ReportsController::class, 'balanceSheet'])->name('reports.balance-sheet');
    Route::get('/reports/trial-balance', [ReportsController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('/reports/cash-flow', [ReportsController::class, 'cashFlow'])->name('reports.cash-flow');
    Route::prefix('vehicles')->name('vehicles.')->middleware('team.module:travel')->group(function () {
        Route::get('/trips', [TripController::class, 'index'])->name('trips.index');
        Route::get('/trips/export', [TripController::class, 'exportCsv'])->name('trips.export');
        Route::get('/trips/export-pdf', [TripController::class, 'exportPdf'])->name('trips.export-pdf');
        Route::delete('/trips/bulk', [TripController::class, 'bulkDestroy'])->name('trips.bulk-destroy');
        Route::get('/trips/imports', [TripImportController::class, 'index'])->name('trips.imports.index');
        Route::post('/trips/imports/{import}/undo', [TripImportController::class, 'undo'])->name('trips.imports.undo');
        Route::get('/trips/import', [TripImportController::class, 'create'])->name('trips.import.create');
        Route::post('/trips/import', [TripImportController::class, 'store'])->name('trips.import.store');
        Route::get('/trips/import/preview', [TripImportController::class, 'preview'])->name('trips.import.preview');
        Route::post('/trips/import/confirm', [TripImportController::class, 'confirm'])->name('trips.import.confirm');
        Route::get('/trips/create', [TripController::class, 'create'])->name('trips.create');
        Route::post('/trips', [TripController::class, 'store'])->name('trips.store');
        Route::get('/trips/{trip}/edit', [TripController::class, 'edit'])->name('trips.edit');
        Route::put('/trips/{trip}', [TripController::class, 'update'])->name('trips.update');
        Route::post('/trips/{trip}/toggle-purpose', [TripController::class, 'togglePurpose'])->name('trips.toggle-purpose');
        Route::delete('/trips/{trip}', [TripController::class, 'destroy'])->name('trips.destroy');

        Route::get('/', [VehicleController::class, 'index'])->name('index');
        Route::get('/create', [VehicleController::class, 'create'])->name('create');
        Route::post('/', [VehicleController::class, 'store'])->name('store');
        Route::get('/{vehicle}', [VehicleController::class, 'show'])->name('show');
        Route::get('/{vehicle}/edit', [VehicleController::class, 'edit'])->name('edit');
        Route::put('/{vehicle}', [VehicleController::class, 'update'])->name('update');
        Route::delete('/{vehicle}', [VehicleController::class, 'destroy'])->name('destroy');
    });
    Route::prefix('invoicing')->name('invoicing.')->group(function () {
        Route::get('/estimates', [EstimateController::class, 'index'])->name('estimates.index');
        Route::get('/estimates/create', [EstimateController::class, 'create'])->name('estimates.create');
        Route::post('/estimates', [EstimateController::class, 'store'])->name('estimates.store');
        Route::get('/estimates/{estimate}', [EstimateController::class, 'show'])->name('estimates.show');
        Route::delete('/estimates/{estimate}', [EstimateController::class, 'destroy'])->name('estimates.destroy');
        Route::get('/estimates/{estimate}/edit', [EstimateController::class, 'edit'])->name('estimates.edit');
        Route::put('/estimates/{estimate}', [EstimateController::class, 'update'])->name('estimates.update');
        Route::post('/estimates/{estimate}/send', [EstimateController::class, 'send'])->name('estimates.send');
        Route::post('/estimates/{estimate}/mark-sent', [EstimateController::class, 'markSent'])->name('estimates.mark-sent');
        Route::post('/estimates/{estimate}/accept', [EstimateController::class, 'accept'])->name('estimates.accept');
        Route::post('/estimates/{estimate}/decline', [EstimateController::class, 'decline'])->name('estimates.decline');
        Route::post('/estimates/{estimate}/convert', [EstimateController::class, 'convert'])->name('estimates.convert');
        Route::get('/estimates/{estimate}/pdf', [EstimatePdfController::class, 'download'])->name('estimates.pdf.download');

        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('/clients/create', [ClientController::class, 'create'])->name('clients.create');
        Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
        Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');

        Route::get('/items', [ItemController::class, 'index'])->name('items.index');
        Route::get('/items/create', [ItemController::class, 'create'])->name('items.create');
        Route::post('/items', [ItemController::class, 'store'])->name('items.store');
        Route::get('/items/{item}', [ItemController::class, 'show'])->name('items.show');
        Route::get('/items/{item}/edit', [ItemController::class, 'edit'])->name('items.edit');
        Route::put('/items/{item}', [ItemController::class, 'update'])->name('items.update');
        Route::delete('/items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');

        Route::get('/recurring', [RecurringInvoiceController::class, 'index'])->name('recurring.index');
        Route::get('/recurring/create', [RecurringInvoiceController::class, 'create'])->name('recurring.create');
        Route::post('/recurring', [RecurringInvoiceController::class, 'store'])->name('recurring.store');
        Route::get('/recurring/{recurring}', [RecurringInvoiceController::class, 'show'])->name('recurring.show');
        Route::get('/recurring/{recurring}/edit', [RecurringInvoiceController::class, 'edit'])->name('recurring.edit');
        Route::put('/recurring/{recurring}', [RecurringInvoiceController::class, 'update'])->name('recurring.update');
        Route::delete('/recurring/{recurring}', [RecurringInvoiceController::class, 'destroy'])->name('recurring.destroy');
        Route::post('/recurring/{recurring}/pause', [RecurringInvoiceController::class, 'pause'])->name('recurring.pause');
        Route::post('/recurring/{recurring}/resume', [RecurringInvoiceController::class, 'resume'])->name('recurring.resume');
        Route::post('/recurring/{recurring}/complete', [RecurringInvoiceController::class, 'complete'])->name('recurring.complete');
        Route::post('/recurring/{recurring}/generate', [RecurringInvoiceController::class, 'generateNow'])->name('recurring.generate');

        Route::get('/exchange-rate', ExchangeRateController::class)->name('exchange-rate');
        Route::post('/invoices/export-pdf-zip', [InvoicePdfController::class, 'downloadZip'])->name('invoices.export-pdf-zip');
        Route::post('/invoices/bulk-mark-sent', [InvoiceController::class, 'bulkMarkSent'])->name('invoices.bulk-mark-sent');
        Route::post('/invoices/bulk-void', [InvoiceController::class, 'bulkVoid'])->name('invoices.bulk-void');
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
        Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
        Route::post('/invoices/{invoice}/remind', [InvoiceController::class, 'remind'])->name('invoices.remind');
        Route::post('/invoices/{invoice}/mark-sent', [InvoiceController::class, 'markSent'])->name('invoices.mark-sent');
        Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::post('/invoices/{invoice}/unvoid', [InvoiceController::class, 'unvoid'])->name('invoices.unvoid');
        Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
        Route::post('/invoices/{invoice}/payments/{payment}/undo', [InvoiceController::class, 'undoPayment'])->name('invoices.payments.undo');
        Route::get('/invoices/{invoice}/payments/{payment}/pdf', [PaymentReceiptController::class, 'download'])->name('invoices.payments.receipt.download');
        Route::get('/invoices/{invoice}/payments/{payment}/pdf/preview', [PaymentReceiptController::class, 'preview'])->name('invoices.payments.receipt.preview');
        Route::post('/invoices/{invoice}/payments/{payment}/send-receipt', [PaymentReceiptController::class, 'send'])->name('invoices.payments.receipt.send');
        Route::post('/invoices/{invoice}/online-payments', [InvoiceOnlinePaymentController::class, 'store'])->name('invoices.online-payments.store');
        Route::post('/invoices/{invoice}/public-pay-link', [InvoiceController::class, 'storePublicPayLink'])->name('invoices.public-pay-link.store');
        Route::get('/invoices/{invoice}/public-pay-qr', [InvoiceController::class, 'publicPayQr'])->name('invoices.public-pay-qr');
    });
    Route::get('/invoices/{invoice}/pdf', [InvoicePdfController::class, 'download'])->name('invoices.pdf.download');
    Route::get('/invoices/{invoice}/pdf/preview', [InvoicePdfController::class, 'preview'])->name('invoices.pdf.preview');
});
