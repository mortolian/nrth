<?php

use App\Modules\Wealth\Http\Controllers\WealthAllowanceController;
use App\Modules\Wealth\Http\Controllers\WealthAssetController;
use App\Modules\Wealth\Http\Controllers\WealthDashboardController;
use App\Modules\Wealth\Http\Controllers\WealthHistoryController;
use App\Modules\Wealth\Http\Controllers\WealthPortfolioController;
use App\Modules\Wealth\Http\Controllers\WealthTransactionController;
use App\Modules\Wealth\Http\Controllers\WealthValuationController;
use Illuminate\Support\Facades\Route;

Route::get('/', WealthDashboardController::class)->name('index');
Route::get('/history', WealthHistoryController::class)->name('history');

Route::post('/portfolios', [WealthPortfolioController::class, 'store'])->name('portfolios.store');
Route::put('/portfolios/{portfolio}', [WealthPortfolioController::class, 'update'])->name('portfolios.update');
Route::post('/portfolios/{portfolio}/select', [WealthPortfolioController::class, 'select'])->name('portfolios.select');
Route::delete('/portfolios/{portfolio}', [WealthPortfolioController::class, 'destroy'])->name('portfolios.destroy');

Route::get('/assets/create', [WealthAssetController::class, 'create'])->name('assets.create');
Route::post('/assets', [WealthAssetController::class, 'store'])->name('assets.store');
Route::get('/assets/{asset}', [WealthAssetController::class, 'show'])->name('assets.show');
Route::get('/assets/{asset}/edit', [WealthAssetController::class, 'edit'])->name('assets.edit');
Route::put('/assets/{asset}', [WealthAssetController::class, 'update'])->name('assets.update');
Route::delete('/assets/{asset}', [WealthAssetController::class, 'destroy'])->name('assets.destroy');

Route::post('/assets/{asset}/valuations', [WealthValuationController::class, 'store'])->name('assets.valuations.store');
Route::delete('/assets/{asset}/valuations/{valuation}', [WealthValuationController::class, 'destroy'])->name('assets.valuations.destroy');

Route::post('/assets/{asset}/transactions', [WealthTransactionController::class, 'store'])->name('assets.transactions.store');
Route::delete('/assets/{asset}/transactions/{transaction}', [WealthTransactionController::class, 'destroy'])->name('assets.transactions.destroy');

Route::get('/allowances', [WealthAllowanceController::class, 'index'])->name('allowances.index');
Route::post('/allowances', [WealthAllowanceController::class, 'store'])->name('allowances.store');
Route::delete('/allowances/{allowance}', [WealthAllowanceController::class, 'destroy'])->name('allowances.destroy');
