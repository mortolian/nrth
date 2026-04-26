<?php

use App\Http\Controllers\Web\Accounting\AccountStatementController;
use App\Http\Controllers\Web\Accounting\ChartOfAccountsController;
use App\Http\Controllers\Web\Accounting\GeneralLedgerController;
use App\Http\Controllers\Web\Accounting\TransactionController;
use App\Http\Controllers\Web\BudgetingController;
use App\Http\Controllers\Web\Contracting\ContractController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ExpensesController;
use App\Http\Controllers\Web\InvoicePdfController;
use App\Http\Controllers\Web\Invoicing\ClientController;
use App\Http\Controllers\Web\Invoicing\InvoiceController;
use App\Http\Controllers\Web\OnboardingController;
use App\Http\Controllers\Web\ReportsController;
use App\Http\Controllers\Web\Settings\CompanySettingsController;
use App\Http\Controllers\Web\Settings\TeamSettingsController;
use App\Http\Controllers\Web\Settings\UserPreferencesController;
use App\Http\Controllers\Web\Tax\ProvisionalTaxController;
use App\Http\Controllers\Web\Tax\TaxDocumentsController;
use App\Http\Controllers\Web\Tax\VATController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

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
    Route::get('/time', function () {
        return Inertia::render('TimeTracking/Index');
    })->name('time.index');
    Route::get('/settings/company', [CompanySettingsController::class, 'edit'])->name('settings.company');
    Route::post('/settings/company', [CompanySettingsController::class, 'update'])->name('settings.company.update');
    Route::get('/settings/team', [TeamSettingsController::class, 'edit'])->name('settings.team');
    Route::put('/user/preferences', [UserPreferencesController::class, 'update'])->name('user-preferences.update');
    Route::get('/expenses', [ExpensesController::class, 'index'])->name('expenses.index');
    Route::get('/expenses/create', [ExpensesController::class, 'create'])->name('expenses.create');
    Route::post('/expenses', [ExpensesController::class, 'store'])->name('expenses.store');
    Route::get('/accounting/transactions', [TransactionController::class, 'index'])->name('accounting.transactions.index');
    Route::get('/accounting/journal', GeneralLedgerController::class)->name('accounting.journal.index');
    Route::get('/accounting/accounts', ChartOfAccountsController::class)->name('accounting.accounts.index');
    Route::get('/accounting/accounts/{account}/statement', AccountStatementController::class)->name('accounting.accounts.statement');
    Route::get('/budgeting', [BudgetingController::class, 'index'])->name('budgeting.index');
    Route::get('/budgeting/create', [BudgetingController::class, 'create'])->name('budgeting.create');
    Route::post('/budgeting', [BudgetingController::class, 'store'])->name('budgeting.store');
    Route::get('/budgeting/{budget}/edit', [BudgetingController::class, 'edit'])->name('budgeting.edit');
    Route::put('/budgeting/{budget}', [BudgetingController::class, 'update'])->name('budgeting.update');
    Route::get('/tax/vat', [VATController::class, 'index'])->name('tax.vat.index');
    Route::post('/tax/vat/periods/{period}/submit', [VATController::class, 'submit'])->name('tax.vat.submit');
    Route::get('/tax/provisional', [ProvisionalTaxController::class, 'index'])->name('tax.provisional.index');
    Route::get('/tax/documents', TaxDocumentsController::class)->name('tax.documents.index');
    Route::get('/reports/profit-loss', [ReportsController::class, 'profitLoss'])->name('reports.profit-loss');
    Route::get('/reports/balance-sheet', [ReportsController::class, 'balanceSheet'])->name('reports.balance-sheet');
    Route::get('/reports/trial-balance', [ReportsController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('/reports/cash-flow', [ReportsController::class, 'cashFlow'])->name('reports.cash-flow');
    Route::prefix('contracting')->name('contracting.')->group(function () {
        Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::get('/contracts/create', [ContractController::class, 'create'])->name('contracts.create');
        Route::post('/contracts', [ContractController::class, 'store'])->name('contracts.store');
        Route::get('/contracts/{contract}/edit', [ContractController::class, 'edit'])->name('contracts.edit');
        Route::put('/contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update');
        Route::post('/contracts/{contract}/generate-invoice', [ContractController::class, 'generateInvoice'])->name('contracts.generate-invoice');
    });
    Route::prefix('invoicing')->name('invoicing.')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('/clients/create', [ClientController::class, 'create'])->name('clients.create');
        Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
        Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');

        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
        Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
    });
    Route::get('/invoices/{invoice}/pdf', [InvoicePdfController::class, 'download'])->name('invoices.pdf.download');
});
